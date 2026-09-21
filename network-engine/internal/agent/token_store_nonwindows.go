//go:build !windows

package agent

import "context"

func StoreProtectedAgentToken(context.Context, string, string) error { return ErrAgentTokenUnavailable }
func loadProtectedAgentToken(string) (string, error)                 { return "", ErrAgentTokenUnavailable }
