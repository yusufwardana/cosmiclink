package provider

import (
	"context"
	"log/slog"
	"testing"
	"time"
)

func TestSilentRouterOSLogHandlerNeverEnablesOrEmitsRecords(t *testing.T) {
	handler := SilentRouterOSLogHandler()
	if handler.Enabled(context.Background(), slog.LevelDebug) || handler.Enabled(context.Background(), slog.LevelError) {
		t.Fatal("silent handler enabled a log level")
	}
	if err := handler.Handle(context.Background(), slog.NewRecord(time.Now(), slog.LevelError, "DO_NOT_LEAK_OPERATOR_SECRET_123", 0)); err != nil {
		t.Fatal(err)
	}
}
