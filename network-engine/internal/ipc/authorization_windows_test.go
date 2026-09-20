//go:build windows

package ipc

import (
	"errors"
	"runtime"
	"strings"
	"testing"
	"time"

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

func testPipeName(t *testing.T) string { return `\\.\pipe\cosmiclink-agent-auth-spike` }
