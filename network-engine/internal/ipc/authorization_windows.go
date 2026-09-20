//go:build windows

package ipc

import (
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
	pingRequest = "PING"
	pipeSDDL    = "D:P(A;;GA;;;SY)(A;;GA;;;BA)"
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

var impersonateNamedPipeClient = windows.NewLazySystemDLL("advapi32.dll").NewProc("ImpersonateNamedPipeClient")

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
	return windows.CloseHandle(s.pipe)
}

func (s *AuthorizationServer) ServeOnce() string {
	if s == nil || s.closed.Load() {
		return "DENIED"
	}
	if err := windows.ConnectNamedPipe(s.pipe, nil); err != nil && !errors.Is(err, windows.ERROR_PIPE_CONNECTED) {
		return "DENIED"
	}
	file := os.NewFile(uintptr(s.pipe), "cosmiclink-auth-spike")
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

	if err := impersonatePipeClient(windows.Handle(client.Fd())); err != nil {
		return ErrImpersonationFailed
	}
	revertAttempted := false
	defer func() {
		revertAttempted = true
		if revertErr := windows.RevertToSelf(); revertErr != nil && err == nil {
			err = ErrRevertFailed
		}
		_ = revertAttempted
	}()

	var token windows.Token
	if err := windows.OpenThreadToken(windows.CurrentThread(), windows.TOKEN_QUERY, true, &token); err != nil {
		return ErrCallerTokenFailed
	}
	defer token.Close()
	adminSID, err := windows.CreateWellKnownSid(windows.WinBuiltinAdministratorsSid)
	if err != nil {
		return ErrAuthorizationFailed
	}
	isMember, err := token.IsMember(adminSID)
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
