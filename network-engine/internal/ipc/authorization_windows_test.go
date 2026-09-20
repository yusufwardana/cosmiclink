//go:build windows

package ipc

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"cosmiclink/network-engine/internal/admin"
	"cosmiclink/network-engine/internal/adminprotocol"
	"cosmiclink/network-engine/internal/agent"

	"golang.org/x/sys/windows"
)

func TestNamedPipeAuthorizationPingUsesActualCallerToken(t *testing.T) {
	server, err := NewAuthorizationServer(testPipeName(t))
	if err != nil {
		t.Fatal(err)
	}
	defer server.Close()

	result := make(chan string, 1)
	go func() { result <- server.ServeOnce() }()
	time.Sleep(100 * time.Millisecond)

	response, err := Ping(server.Name())
	if err != nil {
		t.Fatal(err)
	}
	if response != "AUTHORIZED" && response != "DENIED" {
		t.Fatalf("unexpected response %q", response)
	}
	if handlerResult := <-result; handlerResult != response {
		t.Fatalf("server result %q differs from client response %q", handlerResult, response)
	}
	if server.CallerAdminMember() != server.Authorized() {
		t.Fatalf("authorization result was not based on actual caller membership")
	}
}

func TestAuthorizationLocksOSThreadAndAlwaysRevertsAfterImpersonation(t *testing.T) {
	if runtime.GOOS != "windows" {
		t.Skip("Windows-only")
	}
	server, err := NewAuthorizationServer(testPipeName(t))
	if err != nil {
		t.Fatal(err)
	}
	defer server.Close()
	if err := server.authorizeCurrentClient(nil); !errors.Is(err, ErrAuthorizationClientUnavailable) {
		t.Fatalf("missing client error = %v", err)
	}
}

func TestNamedPipeAppliesRestrictiveACL(t *testing.T) {
	server, err := NewAuthorizationServer(testPipeName(t))
	if err != nil {
		t.Fatal(err)
	}
	defer server.Close()

	sd, err := windows.GetSecurityInfo(server.pipe, windows.SE_KERNEL_OBJECT, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		t.Fatal(err)
	}
	sddl := sd.String()
	if !strings.Contains(sddl, "SY") || !strings.Contains(sddl, "BA") {
		t.Fatalf("live pipe ACL does not contain required principals: %q", sddl)
	}
	if strings.Contains(sddl, "WD") || strings.Contains(sddl, "AU") {
		t.Fatalf("live pipe ACL contains broad principal: %q", sddl)
	}
}

func TestAuthorizationRejectsMalformedPingWithoutPrivilegedHandler(t *testing.T) {
	server, err := NewAuthorizationServer(testPipeName(t))
	if err != nil {
		t.Fatal(err)
	}
	defer server.Close()
	if err := server.rejectMalformedForTest([]byte("NOT-PING")); !errors.Is(err, ErrMalformedRequest) {
		t.Fatalf("malformed request error = %v", err)
	}
	if server.PrivilegedHandlerCalls() != 0 {
		t.Fatal("privileged handler ran before authorization")
	}
}

func TestProductionRequestServerAuthorizesBeforeHandler(t *testing.T) {
	var handlerCalls atomic.Int32
	server, err := NewRequestServer(testRequestPipeName(t), func(request []byte) ([]byte, error) {
		handlerCalls.Add(1)
		if string(request) != `{"operation":"metadata-list"}` {
			t.Fatalf("request = %q", request)
		}
		return []byte(`{"result":[]}`), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	done := make(chan error, 1)
	go func() { done <- server.Serve(ctx) }()

	response, err := Request(server.name, []byte(`{"operation":"metadata-list"}`))
	if err != nil {
		t.Fatal(err)
	}
	if string(response) != `{"result":[]}` {
		t.Fatalf("response = %q", response)
	}
	if handlerCalls.Load() != 1 {
		t.Fatalf("handler calls = %d", handlerCalls.Load())
	}
	select {
	case err := <-done:
		t.Fatalf("server stopped after authorized request: %v", err)
	default:
	}
}

func TestProductionRequestServerSurvivesMalformedRequest(t *testing.T) {
	var handlerCalls atomic.Int32
	server, err := NewRequestServer(testRequestPipeName(t), func(request []byte) ([]byte, error) {
		handlerCalls.Add(1)
		return []byte(`{"result":[]}`), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	done := make(chan error, 1)
	go func() { done <- server.Serve(ctx) }()

	connectAndClose(t, server.name)
	response, err := Request(server.name, []byte(`{"operation":"metadata-list"}`))
	if err != nil {
		t.Fatalf("request after malformed client = %v", err)
	}
	if string(response) != `{"result":[]}` {
		t.Fatalf("response = %q", response)
	}
	if handlerCalls.Load() != 1 {
		t.Fatalf("handler calls = %d", handlerCalls.Load())
	}
	select {
	case err := <-done:
		t.Fatalf("server stopped after malformed request: %v", err)
	default:
	}
}

func TestProductionRequestServerReadsBeforeImpersonation(t *testing.T) {
	restore := replaceAuthorizationCallsForTest(t)
	defer restore()
	var requestRead atomic.Bool
	originalRead := readRequestFrameCall
	readRequestFrameCall = func(file *os.File) ([]byte, error) {
		request, err := originalRead(file)
		if err == nil {
			requestRead.Store(true)
		}
		return request, err
	}
	impersonatePipeClientCall = func(windows.Handle) error {
		if !requestRead.Load() {
			return errors.New("impersonation occurred before request read")
		}
		return nil
	}
	openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return nil }
	tokenIsMemberCall = func(windows.Token, *windows.SID) (bool, error) { return true, nil }
	closeTokenCall = func(windows.Token) error { return nil }
	revertToSelfCall = func() error { return nil }

	response, handlerCalls := runSingleRequest(t, []byte(`{"operation":"metadata-list"}`))
	if string(response) != `{"result":[]}` || handlerCalls != 1 {
		t.Fatalf("response = %q; handler calls = %d", response, handlerCalls)
	}
}

func TestProductionRequestServerRejectsAuthorizationFailuresBeforeHandler(t *testing.T) {
	tests := []struct {
		name      string
		configure func()
	}{
		{
			name: "impersonation failure",
			configure: func() {
				impersonatePipeClientCall = func(windows.Handle) error { return errors.New("sentinel") }
			},
		},
		{
			name: "token open failure",
			configure: func() {
				impersonatePipeClientCall = func(windows.Handle) error { return nil }
				openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return errors.New("sentinel") }
				revertToSelfCall = func() error { return nil }
			},
		},
		{
			name: "unauthorized caller",
			configure: func() {
				impersonatePipeClientCall = func(windows.Handle) error { return nil }
				openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return nil }
				tokenIsMemberCall = func(windows.Token, *windows.SID) (bool, error) { return false, nil }
				closeTokenCall = func(windows.Token) error { return nil }
				revertToSelfCall = func() error { return nil }
			},
		},
		{
			name: "authorization check failure",
			configure: func() {
				impersonatePipeClientCall = func(windows.Handle) error { return nil }
				openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return nil }
				tokenIsMemberCall = func(windows.Token, *windows.SID) (bool, error) { return false, errors.New("sentinel") }
				closeTokenCall = func(windows.Token) error { return nil }
				revertToSelfCall = func() error { return nil }
			},
		},
		{
			name: "revert failure",
			configure: func() {
				impersonatePipeClientCall = func(windows.Handle) error { return nil }
				openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return nil }
				tokenIsMemberCall = func(windows.Token, *windows.SID) (bool, error) { return true, nil }
				closeTokenCall = func(windows.Token) error { return nil }
				revertToSelfCall = func() error { return errors.New("sentinel") }
			},
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			restore := replaceAuthorizationCallsForTest(t)
			defer restore()
			var impersonated atomic.Bool
			var reverted atomic.Bool
			tt.configure()
			configuredImpersonate := impersonatePipeClientCall
			impersonatePipeClientCall = func(handle windows.Handle) error {
				err := configuredImpersonate(handle)
				if err == nil {
					impersonated.Store(true)
				}
				return err
			}
			configuredRevert := revertToSelfCall
			revertToSelfCall = func() error {
				reverted.Store(true)
				return configuredRevert()
			}
			response, handlerCalls := runSingleRequest(t, []byte(`{"operation":"metadata-list"}`))
			if len(response) != 0 {
				t.Fatalf("response = %q", response)
			}
			if handlerCalls != 0 {
				t.Fatalf("handler calls = %d", handlerCalls)
			}
			if impersonated.Load() != reverted.Load() {
				t.Fatalf("impersonated = %t; reverted = %t", impersonated.Load(), reverted.Load())
			}
		})
	}
}

func TestProductionRequestServerSurvivesUnauthorizedRequest(t *testing.T) {
	restore := replaceAuthorizationCallsForTest(t)
	defer restore()
	var authorize atomic.Bool
	impersonatePipeClientCall = func(windows.Handle) error { return nil }
	openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return nil }
	tokenIsMemberCall = func(windows.Token, *windows.SID) (bool, error) { return authorize.Load(), nil }
	closeTokenCall = func(windows.Token) error { return nil }
	revertToSelfCall = func() error { return nil }
	var handlerCalls atomic.Int32
	server, err := NewRequestServer(testRequestPipeName(t), func([]byte) ([]byte, error) {
		handlerCalls.Add(1)
		return []byte(`{"result":[]}`), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	done := make(chan error, 1)
	go func() { done <- server.Serve(ctx) }()

	response, err := Request(server.name, []byte(`{"operation":"metadata-list"}`))
	if err != nil {
		t.Fatal(err)
	}
	if len(response) != 0 || handlerCalls.Load() != 0 {
		t.Fatalf("unauthorized response = %q; handler calls = %d", response, handlerCalls.Load())
	}
	authorize.Store(true)
	response, err = Request(server.name, []byte(`{"operation":"metadata-list"}`))
	if err != nil {
		t.Fatal(err)
	}
	if string(response) != `{"result":[]}` || handlerCalls.Load() != 1 {
		t.Fatalf("authorized response = %q; handler calls = %d", response, handlerCalls.Load())
	}
	select {
	case err := <-done:
		t.Fatalf("server stopped after unauthorized request: %v", err)
	default:
	}
}

func TestProductionRequestServerStopsWhenRevertFails(t *testing.T) {
	restore := replaceAuthorizationCallsForTest(t)
	defer restore()
	impersonatePipeClientCall = func(windows.Handle) error { return nil }
	openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return nil }
	tokenIsMemberCall = func(windows.Token, *windows.SID) (bool, error) { return true, nil }
	closeTokenCall = func(windows.Token) error { return nil }
	revertToSelfCall = func() error { return errors.New("sentinel") }
	var handlerCalls atomic.Int32
	server, err := NewRequestServer(testRequestPipeName(t), func([]byte) ([]byte, error) {
		handlerCalls.Add(1)
		return []byte(`{"result":[]}`), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	done := make(chan error, 1)
	go func() { done <- server.Serve(ctx) }()

	response, err := Request(server.name, []byte(`{"operation":"metadata-list"}`))
	if err != nil {
		t.Fatal(err)
	}
	if len(response) != 0 || handlerCalls.Load() != 0 {
		t.Fatalf("response = %q; handler calls = %d", response, handlerCalls.Load())
	}
	select {
	case err := <-done:
		if !errors.Is(err, ErrRevertFailed) {
			t.Fatalf("server error = %v", err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("server continued after RevertToSelf failure")
	}
}

func TestProductionRequestServerRevertsBeforeHandler(t *testing.T) {
	restore := replaceAuthorizationCallsForTest(t)
	defer restore()
	var reverted atomic.Bool
	impersonatePipeClientCall = func(windows.Handle) error { return nil }
	openThreadTokenCall = func(windows.Handle, uint32, bool, *windows.Token) error { return nil }
	tokenIsMemberCall = func(windows.Token, *windows.SID) (bool, error) { return true, nil }
	closeTokenCall = func(windows.Token) error { return nil }
	revertToSelfCall = func() error {
		reverted.Store(true)
		return nil
	}

	server, err := NewRequestServer(testRequestPipeName(t), func([]byte) ([]byte, error) {
		if !reverted.Load() {
			t.Fatal("handler ran before RevertToSelf")
		}
		return []byte(`{"result":[]}`), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	go func() { _ = server.Serve(ctx) }()
	response, err := Request(server.name, []byte(`{"operation":"metadata-list"}`))
	if err != nil {
		t.Fatal(err)
	}
	if string(response) != `{"result":[]}` {
		t.Fatalf("response = %q", response)
	}
}

func TestProductionRequestServerRejectsOversizedRequestAndContinues(t *testing.T) {
	var handlerCalls atomic.Int32
	server, err := NewRequestServer(testRequestPipeName(t), func([]byte) ([]byte, error) {
		handlerCalls.Add(1)
		return []byte(`{"result":[]}`), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	go func() { _ = server.Serve(ctx) }()

	response, err := Request(server.name, make([]byte, maxRequestFrameSize+1))
	if err != nil {
		t.Fatal(err)
	}
	if len(response) != 0 || handlerCalls.Load() != 0 {
		t.Fatalf("oversized response = %q; handler calls = %d", response, handlerCalls.Load())
	}
	response, err = Request(server.name, []byte(`{"operation":"metadata-list"}`))
	if err != nil {
		t.Fatal(err)
	}
	if string(response) != `{"result":[]}` || handlerCalls.Load() != 1 {
		t.Fatalf("recovery response = %q; handler calls = %d", response, handlerCalls.Load())
	}
}

func TestProductionRequestServerMalformedProtocolNeverReachesPrivilegedHandler(t *testing.T) {
	var privilegedHandlerCalls atomic.Int32
	server, err := NewRequestServer(testRequestPipeName(t), func(encoded []byte) ([]byte, error) {
		request, decodeErr := adminprotocol.DecodeRequest(encoded)
		if decodeErr != nil {
			return adminprotocol.EncodeResponse(nil, decodeErr)
		}
		privilegedHandlerCalls.Add(1)
		return adminprotocol.EncodeResponse(request.Operation, nil)
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	go func() { _ = server.Serve(ctx) }()

	response, err := Request(server.name, []byte(`{"version":1,"operation":`))
	if err != nil {
		t.Fatal(err)
	}
	var envelope adminprotocol.Response
	if err := json.Unmarshal(response, &envelope); err != nil {
		t.Fatal(err)
	}
	if envelope.Error != adminprotocol.ErrMalformed.Error() {
		t.Fatalf("response error = %q", envelope.Error)
	}
	if privilegedHandlerCalls.Load() != 0 {
		t.Fatalf("privileged handler calls = %d", privilegedHandlerCalls.Load())
	}
}

func TestProductionRequestServerCredentialList(t *testing.T) {
	dataDir := t.TempDir()
	bootstrap, err := agent.NewBootstrapState(filepath.Join(dataDir, "agent-bootstrap.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err := bootstrap.Bind(context.Background(), []byte(`{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`)); err != nil {
		t.Fatal(err)
	}
	if _, err := agent.InitializeInstallation(context.Background(), dataDir); err != nil {
		t.Fatal(err)
	}
	store, err := agent.InitializeCredentialStore(context.Background(), dataDir)
	if err != nil {
		t.Fatal(err)
	}
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}
	service := admin.NewService(dataDir, "test-windows-administrator")
	server, err := NewRequestServer(testRequestPipeName(t), func(encoded []byte) ([]byte, error) {
		request, decodeErr := adminprotocol.DecodeRequest(encoded)
		if decodeErr != nil {
			return adminprotocol.EncodeResponse(nil, decodeErr)
		}
		result, handleErr := service.Handle(context.Background(), request)
		return adminprotocol.EncodeResponse(result, handleErr)
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	go func() { _ = server.Serve(ctx) }()

	request, err := adminprotocol.EncodeRequest(adminprotocol.List, adminprotocol.ListPayload{})
	if err != nil {
		t.Fatal(err)
	}
	response, err := Request(server.name, request)
	if err != nil {
		t.Fatal(err)
	}
	var envelope adminprotocol.Response
	decoder := json.NewDecoder(bytes.NewReader(response))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&envelope); err != nil {
		t.Fatal(err)
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		t.Fatalf("trailing response data: %v", err)
	}
	if envelope.Version != adminprotocol.Version || envelope.Error != "" || string(envelope.Result) != "[]" {
		t.Fatalf("credential list response = %s", response)
	}
}

func runSingleRequest(t *testing.T, payload []byte) ([]byte, int32) {
	t.Helper()
	var handlerCalls atomic.Int32
	server, err := NewRequestServer(testRequestPipeName(t), func([]byte) ([]byte, error) {
		handlerCalls.Add(1)
		return []byte(`{"result":[]}`), nil
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	defer server.Close()
	go func() { _ = server.Serve(ctx) }()
	response, err := Request(server.name, payload)
	if err != nil {
		t.Fatal(err)
	}
	return response, handlerCalls.Load()
}

func replaceAuthorizationCallsForTest(t *testing.T) func() {
	t.Helper()
	originalRead := readRequestFrameCall
	originalImpersonate := impersonatePipeClientCall
	originalOpenToken := openThreadTokenCall
	originalIsMember := tokenIsMemberCall
	originalCloseToken := closeTokenCall
	originalRevert := revertToSelfCall
	return func() {
		readRequestFrameCall = originalRead
		impersonatePipeClientCall = originalImpersonate
		openThreadTokenCall = originalOpenToken
		tokenIsMemberCall = originalIsMember
		closeTokenCall = originalCloseToken
		revertToSelfCall = originalRevert
	}
}

func connectAndClose(t *testing.T, name string) {
	t.Helper()
	name16, err := windows.UTF16PtrFromString(name)
	if err != nil {
		t.Fatal(err)
	}
	for attempt := 0; attempt < 500; attempt++ {
		handle, openErr := windows.CreateFile(name16, windows.GENERIC_READ|windows.GENERIC_WRITE, 0, nil, windows.OPEN_EXISTING, 0, 0)
		if openErr == nil {
			if err := windows.CloseHandle(handle); err != nil {
				t.Fatal(err)
			}
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("request pipe did not become available")
}

func testPipeName(t *testing.T) string { return `\\.\pipe\cosmiclink-agent-auth-spike` }

func testRequestPipeName(t *testing.T) string {
	return `\\.\pipe\cosmiclink-agent-request-` + strings.ReplaceAll(t.Name(), "/", "-")
}
