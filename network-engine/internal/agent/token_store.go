package agent

import (
	"context"
	"errors"
	"os"
)

var ErrAgentTokenUnavailable = errors.New("agent token unavailable")

func ResolveToken(ctx context.Context, dataDir, token string) (string, error) {
	if err := ctx.Err(); err != nil {
		return "", err
	}
	if token != "" {
		return token, nil
	}
	if dataDir == "" {
		return "", ErrAgentTokenUnavailable
	}
	return loadProtectedAgentToken(dataDir)
}

func tokenFilePath(dataDir string) string {
	return dataDir + string(os.PathSeparator) + "agent-token.protected"
}
