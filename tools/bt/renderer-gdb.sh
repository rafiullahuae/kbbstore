#!/bin/sh
# Lane BT -- Chromium's --renderer-cmd-prefix target. Runs each renderer under
# gdb and, on every document a frame attaches, calls the real
# blink::LocalFrame::SetTextZoomFactor(BT_TEXT_ZOOM): the exact call Android
# WebView makes when the phone's font size is large (AwSettings
# mTextSizePercent = 100 x Configuration.fontScale -> aw_settings.cc
# SetTextZoomFactor(percent / 100) -> WebFrameWidgetImpl::SetTextZoomFactor ->
# LocalFrame::SetTextZoomFactor). Needs a Chromium with its symbol table
# (Playwright's chromium-1194, 141.0.7390.37, has one) and gdb.
# See tools/bt-shots.cjs and docs/lane-bt/README.md.
Z=${BT_TEXT_ZOOM:-1}
D=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)/storage/bt-logs
mkdir -p "$D"
C=$D/gdb-zoom-$Z.cmd
cat > "$C.$$" <<GDB
set pagination off
set confirm off
set print thread-events off
set print inferior-events off
set auto-load safe-path /nonexistent
handle SIGUSR1 SIGUSR2 SIGPIPE SIGCHLD SIGSYS SIG32 SIG33 SIG34 SIG35 SIG36 SIG37 SIG38 SIG39 SIG40 SIGALRM SIGVTALRM SIGPROF SIGSEGV nostop noprint pass
break blink::LocalFrame::DidAttachDocument
commands
silent
set \$btf = \$rdi
call (void)((void(*)(void*,float))'blink::LocalFrame::SetTextZoomFactor(float)')(\$btf, $Z)
continue
end
run
GDB
mv "$C.$$" "$C"
exec gdb -q -batch -nx -x "$C" --args "$@"
