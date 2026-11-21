//go:build !windows

package main

import (
	"os/exec"
	"syscall"
)

// ccGroup: a run is its own process group, so a timeout stops Claude Code AND the connector it started.
func ccGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return nil
		}
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
}
