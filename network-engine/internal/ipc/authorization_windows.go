//go:build windows

package ipc

import (
	"context"
	"errors"
	"io"
	"os"
	"runtime"
	"strings"
	"sync/atomic"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

const (
	pingRequest         = "PING"
	pipeSDDL            = "D:P(A;;GA;;;SY)(A;;GA;;;BA)"
	maxRequestFrameSize = 1024 * 1024
)

var (
	ErrIPCUnavailable                 = errors.New("IPC_UNAVAILABLE")
	ErrIPCConnectionFailed            = errors.New("IPC_CONNECTION_FAILED")
	ErrImpersonationFailed            = errors.New("IPC_IMPERSONATION_FAILED")
	ErrCallerTokenFailed              = errors.New("IPC_CALLER_TOKEN_FAILED")
	ErrCallerUnauthorized             = errors.New("IPC_CALLER_UNAUTHORIZED")
	ErrAuthorizationFailed            = errors.New("IPC_AUTHORIZATION_FAILED")
	ErrRevertFailed                   = errors.New("IPC_REVERT_FAILED")
	ErrMalformedRequest               = errors.New("IPC_MALFORMED_REQUEST")
	ErrAuthorizationClientUnavailable = errors.New("IPC_AUTHORIZATION_CLIENT_UNAVAILABLE")
)

var (
	impersonateNamedPipeClient = windows.NewLazySystemDLL("advapi32.dll").NewProc("ImpersonateNamedPipeClient")
	readRequestFrameCall       = readRequestFrame
	impersonatePipeClientCall  = impersonatePipeClient
	openThreadTokenCall        = windows.OpenThreadToken
	tokenIsMemberCall          = func(token windows.Token, sid *windows.SID) (bool, error) { return token.IsMember(sid) }
	closeTokenCall             = func(token windows.Token) error { return token.Close() }
	revertToSelfCall           = windows.RevertToSelf
)

type AuthorizationServer struct {
	name                   string
	pipe                   windows.Handle
	closed                 atomic.Bool
	callerAdminMember      bool
	authorized             bool
	privilegedHandlerCalls atomic.Int32
}

func NewAuthorizationServer(name string) (*AuthorizationServer, error) {
	return newAuthorizationServer(name, pipeSDDL)
}

func newAuthorizationServer(name, sddl string) (*AuthorizationServer, error) {
	if !strings.HasPrefix(name, `\\.\pipe\`) {
		return nil, ErrIPCUnavailable
	}
	securityDescriptor, err := windows.SecurityDescriptorFromString(sddl)
	if err != nil {
		return nil, ErrIPCUnavailable
	}
	securityAttributes := &windows.SecurityAttributes{
		Length:             uint32(unsafe.Sizeof(windows.SecurityAttributes{})),
		SecurityDescriptor: securityDescriptor,
	}
	name16, err := windows.UTF16PtrFromString(name)
	if err != nil {
		return nil, ErrIPCUnavailable
	}
	pipe, err := windows.CreateNamedPipe(
		name16,
		windows.PIPE_ACCESS_DUPLEX,
		windows.PIPE_TYPE_MESSAGE|windows.PIPE_READMODE_MESSAGE|windows.PIPE_WAIT,
		1,
		4096,
		4096,
		0,
		securityAttributes,
	)
	if err != nil {
		return nil, ErrIPCUnavailable
	}
	return &AuthorizationServer{name: name, pipe: pipe}, nil
}

func (s *AuthorizationServer) Name() string { return s.name }

func (s *AuthorizationServer) Close() error {
	if s == nil || s.closed.Swap(true) {
		return nil
	}
	if s.pipe == windows.InvalidHandle {
		return nil
	}
	return windows.CloseHandle(s.pipe)
}

func (s *AuthorizationServer) takePipeFile(name string) *os.File {
	if s == nil || s.pipe == windows.InvalidHandle {
		return nil
	}
	file := os.NewFile(uintptr(s.pipe), name)
	if file != nil {
		s.pipe = windows.InvalidHandle
	}
	return file
}

func (s *AuthorizationServer) ServeOnce() string {
	if s == nil || s.closed.Load() {
		return "DENIED"
	}
	if err := windows.ConnectNamedPipe(s.pipe, nil); err != nil && !errors.Is(err, windows.ERROR_PIPE_CONNECTED) {
		return "DENIED"
	}
	file := s.takePipeFile("cosmiclink-auth-spike")
	if file == nil {
		return "DENIED"
	}
	defer file.Close()
	if err := s.authorizeCurrentClient(file); err != nil {
		_, _ = file.Write([]byte("DENIED"))
		return "DENIED"
	}
	request := make([]byte, len(pingRequest))
	if _, err := io.ReadFull(file, request); err != nil || string(request) != pingRequest {
		_, _ = file.Write([]byte("DENIED"))
		return "DENIED"
	}
	s.privilegedHandlerCalls.Add(1)
	_, _ = file.Write([]byte("AUTHORIZED"))
	return "AUTHORIZED"
}

func Ping(name string) (string, error) {
	name16, err := windows.UTF16PtrFromString(name)
	if err != nil {
		return "", ErrIPCConnectionFailed
	}
	var pipe windows.Handle
	for attempt := 0; attempt < 500; attempt++ {
		pipe, err = windows.CreateFile(name16, windows.GENERIC_READ|windows.GENERIC_WRITE, 0, nil, windows.OPEN_EXISTING, 0, 0)
		if err == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if err != nil {
		return "", ErrIPCConnectionFailed
	}
	file := os.NewFile(uintptr(pipe), "cosmiclink-auth-spike-client")
	if file == nil {
		_ = windows.CloseHandle(pipe)
		return "", ErrIPCConnectionFailed
	}
	defer file.Close()
	if _, err := file.Write([]byte(pingRequest)); err != nil {
		return "", ErrIPCConnectionFailed
	}
	response := make([]byte, len("AUTHORIZED"))
	var read int
	if read, err = file.Read(response); err != nil {
		return "", ErrIPCConnectionFailed
	}
	return strings.TrimSpace(string(response[:read])), nil
}

func (s *AuthorizationServer) authorizeCurrentClient(client *os.File) (err error) {
	if client == nil {
		return ErrAuthorizationClientUnavailable
	}
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()

	if err := impersonatePipeClientCall(windows.Handle(client.Fd())); err != nil {
		return ErrImpersonationFailed
	}
	defer func() {
		if revertErr := revertToSelfCall(); revertErr != nil && err == nil {
			err = ErrRevertFailed
		}
	}()

	var token windows.Token
	if err := openThreadTokenCall(windows.CurrentThread(), windows.TOKEN_QUERY, true, &token); err != nil {
		return ErrCallerTokenFailed
	}
	defer func() { _ = closeTokenCall(token) }()
	adminSID, err := windows.CreateWellKnownSid(windows.WinBuiltinAdministratorsSid)
	if err != nil {
		return ErrAuthorizationFailed
	}
	isMember, err := tokenIsMemberCall(token, adminSID)
	if err != nil {
		return ErrAuthorizationFailed
	}
	s.callerAdminMember = isMember
	s.authorized = isMember
	if !isMember {
		return ErrCallerUnauthorized
	}
	return nil
}

func impersonatePipeClient(pipe windows.Handle) error {
	result, _, callErr := impersonateNamedPipeClient.Call(uintptr(pipe))
	if result == 0 {
		if callErr != nil {
			return callErr
		}
		return ErrImpersonationFailed
	}
	return nil
}

func (s *AuthorizationServer) CallerAdminMember() bool { return s != nil && s.callerAdminMember }
func (s *AuthorizationServer) Authorized() bool        { return s != nil && s.authorized }
func (s *AuthorizationServer) PrivilegedHandlerCalls() int32 {
	if s == nil {
		return 0
	}
	return s.privilegedHandlerCalls.Load()
}

func (s *AuthorizationServer) rejectMalformedForTest(request []byte) error {
	if string(request) != pingRequest {
		return ErrMalformedRequest
	}
	return nil
}

type RequestHandler func([]byte) ([]byte, error)

type RequestServer struct {
	name    string
	handler RequestHandler
	closed  atomic.Bool
}

func NewRequestServer(name string, handler RequestHandler) (*RequestServer, error) {
	if handler == nil {
		return nil, ErrIPCUnavailable
	}
	return &RequestServer{name: name, handler: handler}, nil
}

func (s *RequestServer) Close() {
	if s != nil {
		s.closed.Store(true)
	}
}

func (s *RequestServer) Serve(ctx context.Context) error {
	for !s.closed.Load() {
		select {
		case <-ctx.Done():
			return ctx.Err()
		default:
		}
		auth, err := NewAuthorizationServer(s.name)
		if err != nil {
			return ErrIPCUnavailable
		}
		err = auth.serveRequest(s.handler)
		_ = auth.Close()
		if errors.Is(err, ErrRevertFailed) {
			return err
		}
	}
	return nil
}

func (s *AuthorizationServer) serveRequest(handler RequestHandler) error {
	if s == nil || handler == nil {
		return ErrIPCUnavailable
	}
	if err := windows.ConnectNamedPipe(s.pipe, nil); err != nil && !errors.Is(err, windows.ERROR_PIPE_CONNECTED) {
		return ErrIPCConnectionFailed
	}
	file := s.takePipeFile("cosmiclink-agent-admin")
	if file == nil {
		return ErrIPCConnectionFailed
	}
	defer file.Close()
	request, err := readRequestFrameCall(file)
	if err != nil {
		return err
	}
	if err := s.authorizeCurrentClient(file); err != nil {
		return err
	}
	response, handlerErr := handler(request)
	if handlerErr != nil && len(response) == 0 {
		return handlerErr
	}
	_, err = file.Write(response)
	if err != nil {
		return ErrIPCConnectionFailed
	}
	return nil
}

func readRequestFrame(file *os.File) ([]byte, error) {
	if file == nil {
		return nil, ErrMalformedRequest
	}
	request := make([]byte, maxRequestFrameSize+1)
	n, err := file.Read(request)
	if err != nil || n == 0 || n > maxRequestFrameSize {
		return nil, ErrMalformedRequest
	}
	return request[:n], nil
}

func Request(name string, payload []byte) ([]byte, error) {
	name16, err := windows.UTF16PtrFromString(name)
	if err != nil {
		return nil, ErrIPCConnectionFailed
	}
	var pipe windows.Handle
	for attempt := 0; attempt < 500; attempt++ {
		pipe, err = windows.CreateFile(name16, windows.GENERIC_READ|windows.GENERIC_WRITE, 0, nil, windows.OPEN_EXISTING, 0, 0)
		if err == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if err != nil {
		return nil, ErrIPCConnectionFailed
	}
	file := os.NewFile(uintptr(pipe), "cosmiclink-agent-admin-client")
	if file == nil {
		_ = windows.CloseHandle(pipe)
		return nil, ErrIPCConnectionFailed
	}
	defer file.Close()
	if _, err := file.Write(payload); err != nil {
		return nil, ErrIPCConnectionFailed
	}
	response, err := io.ReadAll(io.LimitReader(file, 1024*1024))
	if err != nil {
		return nil, ErrIPCConnectionFailed
	}
	return response, nil
}
