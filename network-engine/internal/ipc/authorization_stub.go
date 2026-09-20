//go:build !windows

package ipc

import "errors"

var ErrIPCUnavailable = errors.New("IPC_UNAVAILABLE")

func Ping(string) (string, error) { return "", ErrIPCUnavailable }
