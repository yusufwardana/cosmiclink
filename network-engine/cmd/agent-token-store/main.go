package main

import (
	"context"
	"fmt"
	"io"
	"os"

	"cosmiclink/network-engine/internal/agent"
)

func main() {
	if len(os.Args) != 2 {
		fmt.Fprintln(os.Stderr, "usage: agent-token-store <agent-data-dir>")
		os.Exit(2)
	}
	token, err := io.ReadAll(io.LimitReader(os.Stdin, 513))
	if err != nil || len(token) == 0 || len(token) > 512 {
		os.Exit(1)
	}
	if err := agent.StoreProtectedAgentToken(context.Background(), os.Args[1], string(token)); err != nil {
		os.Exit(1)
	}
}
