//go:build windows

package main

import (
	"unsafe"

	"golang.org/x/sys/windows"
)

// The Win32 the tray needs, and nothing else.
//
// Declared here rather than pulled in from a library because the licence rules
// for this agent are strict — no GPL or AGPL in a closed-source distribution —
// and because a tray icon is about two hundred lines of syscall. A dependency
// for that is a dependency to audit for ever.

var (
	user32   = windows.NewLazySystemDLL("user32.dll")
	shell32  = windows.NewLazySystemDLL("shell32.dll")
	kernel32 = windows.NewLazySystemDLL("kernel32.dll")

	procRegisterClassEx  = user32.NewProc("RegisterClassExW")
	procCreateWindowEx   = user32.NewProc("CreateWindowExW")
	procDefWindowProc    = user32.NewProc("DefWindowProcW")
	procGetMessage       = user32.NewProc("GetMessageW")
	procTranslateMessage = user32.NewProc("TranslateMessage")
	procDispatchMessage  = user32.NewProc("DispatchMessageW")
	procPostQuitMessage  = user32.NewProc("PostQuitMessage")
	procCreatePopupMenu  = user32.NewProc("CreatePopupMenu")
	procAppendMenu       = user32.NewProc("AppendMenuW")
	procDestroyMenu      = user32.NewProc("DestroyMenu")
	procTrackPopupMenu   = user32.NewProc("TrackPopupMenu")
	procSetForeground    = user32.NewProc("SetForegroundWindow")
	procGetCursorPos     = user32.NewProc("GetCursorPos")
	procLoadIcon         = user32.NewProc("LoadIconW")
	procLoadImage        = user32.NewProc("LoadImageW")
	procSetTimer         = user32.NewProc("SetTimer")
	procMessageBox       = user32.NewProc("MessageBoxW")
	procOpenClipboard    = user32.NewProc("OpenClipboard")
	procEmptyClipboard   = user32.NewProc("EmptyClipboard")
	procSetClipboardData = user32.NewProc("SetClipboardData")
	procCloseClipboard   = user32.NewProc("CloseClipboard")

	procShellNotifyIcon = shell32.NewProc("Shell_NotifyIconW")
	procShellExecute    = shell32.NewProc("ShellExecuteW")

	procGlobalAlloc  = kernel32.NewProc("GlobalAlloc")
	procGlobalLock   = kernel32.NewProc("GlobalLock")
	procGlobalUnlock = kernel32.NewProc("GlobalUnlock")
)

const (
	wmDestroy   = 0x0002
	wmClose     = 0x0010
	wmCommand   = 0x0111
	wmTimer     = 0x0113
	wmRButtonUp = 0x0205
	wmLButtonUp = 0x0202
	// wmTrayCallback is ours: WM_APP + 1. The shell sends every icon event to
	// whichever message this asks for.
	wmTrayCallback = 0x8000 + 1

	nimAdd    = 0x00000000
	nimModify = 0x00000001
	nimDelete = 0x00000002

	nifMessage = 0x00000001
	nifIcon    = 0x00000002
	nifTip     = 0x00000004

	mfString    = 0x00000000
	mfSeparator = 0x00000800
	mfGrayed    = 0x00000001
	mfDisabled  = 0x00000002

	tpmRightButton = 0x0002

	cwUseDefault = ^uintptr(0x7FFFFFFF) // 0x80000000 as a signed int

	idiApplication = 32512
	idiWarning     = 32515

	imageIcon      = 1
	lrLoadFromFile = 0x00000010
	lrDefaultSize  = 0x00000040

	cfUnicodeText = 13
	gmemMoveable  = 0x0002

	mbIconInformation = 0x00000040
	mbIconError       = 0x00000010
	swShowNormal      = 1
)

type point struct{ X, Y int32 }

type msg struct {
	HWnd    windows.HWND
	Message uint32
	WParam  uintptr
	LParam  uintptr
	Time    uint32
	Pt      point
}

type wndClassEx struct {
	CbSize        uint32
	Style         uint32
	LpfnWndProc   uintptr
	CbClsExtra    int32
	CbWndExtra    int32
	HInstance     windows.Handle
	HIcon         windows.Handle
	HCursor       windows.Handle
	HbrBackground windows.Handle
	LpszMenuName  *uint16
	LpszClassName *uint16
	HIconSm       windows.Handle
}

// notifyIconData is NOTIFYICONDATAW. Go lays this out the same way the C
// compiler does on amd64, and cbSize is taken from the Go struct rather than
// written as a number so the two cannot drift apart.
type notifyIconData struct {
	CbSize           uint32
	HWnd             windows.HWND
	UID              uint32
	UFlags           uint32
	UCallbackMessage uint32
	HIcon            windows.Handle
	SzTip            [128]uint16
	DwState          uint32
	DwStateMask      uint32
	SzInfo           [256]uint16
	UVersion         uint32
	SzInfoTitle      [64]uint16
	DwInfoFlags      uint32
	GuidItem         windows.GUID
	HBalloonIcon     windows.Handle
}

func utf16(s string) *uint16 {
	p, err := windows.UTF16PtrFromString(s)
	if err != nil {
		// Only possible for a string with a NUL in it, which none of ours has.
		p, _ = windows.UTF16PtrFromString("")
	}

	return p
}

// setTip copies text into the fixed tooltip buffer, truncating rather than
// overrunning. Windows allows 127 characters plus the terminator.
func (n *notifyIconData) setTip(text string) {
	encoded := windows.StringToUTF16(text)
	if len(encoded) > len(n.SzTip) {
		encoded = encoded[:len(n.SzTip)-1]
		encoded = append(encoded, 0)
	}

	n.SzTip = [128]uint16{}
	copy(n.SzTip[:], encoded)
}

func notifyIcon(action uint32, data *notifyIconData) bool {
	data.CbSize = uint32(unsafe.Sizeof(*data))
	ret, _, _ := procShellNotifyIcon.Call(uintptr(action), uintptr(unsafe.Pointer(data)))

	return ret != 0
}
