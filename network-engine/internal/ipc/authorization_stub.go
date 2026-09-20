//go:build !windows

package ipc

import (
	"context"
	"errors"
)

var ErrIPCUnavailable = errors.New("IPC_UNAVAILABLE")

func Ping(string) (string, error) { return "", ErrIPCUnavailable }

type RequestHandler func([]byte) ([]byte, error)
type RequestServer struct{}

func NewRequestServer(string, RequestHandler) (*RequestServer, error) { return nil, ErrIPCUnavailable }
func (*RequestServer) Close()                                         {}
func (*RequestServer) Serve(context.Context) error                    { return ErrIPCUnavailable }
func Request(string, []byte) ([]byte, error)                          { return nil, ErrIPCUnavailable }
