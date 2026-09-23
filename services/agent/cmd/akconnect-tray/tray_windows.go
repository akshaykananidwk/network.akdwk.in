//go:build windows

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

// The icon in the corner of the screen.
//
// Not decoration. It is the only place a customer can see whether the thing is
// working without being told where to look, and the only place they can do the
// three things a support call otherwise starts with: what is my address, open
// the panel, send me the logs.

const (
	idOpenPanel = 1001
	idCopyIP    = 1002
	idDiagnose  = 1003
	idExit      = 1004

	refreshEvery = 5 * time.Second
	refreshTimer = 1
)

type tray struct {
	hwnd    windows.HWND
	data    notifyIconData
	current view
	// icons are loaded once. Loading one per refresh leaks a handle every five
	// seconds, which on a machine left on for a month is a machine that stops
	// drawing menus.
	iconOK   windows.Handle
	iconWarn windows.Handle
}

var active *tray

func runTray() error {
	t, err := newTray()
	if err != nil {
		return err
	}
	defer notifyIcon(nimDelete, &t.data)

	t.refresh()
	procSetTimer.Call(uintptr(t.hwnd), refreshTimer, uintptr(refreshEvery/time.Millisecond), 0)

	return t.pump()
}

// newTray registers the window class, creates the message window and puts the
// icon up.
func newTray() (*tray, error) {
	t := &tray{}
	active = t

	class := utf16(productName + "Tray")

	wc := wndClassEx{
		LpfnWndProc:   windows.NewCallback(wndProc),
		LpszClassName: class,
	}
	wc.CbSize = uint32(unsafe.Sizeof(wc))

	if ret, _, err := procRegisterClassEx.Call(uintptr(unsafe.Pointer(&wc))); ret == 0 {
		return nil, fmt.Errorf("could not register the window class: %w", err)
	}

	hwnd, _, err := procCreateWindowEx.Call(
		0, uintptr(unsafe.Pointer(class)), uintptr(unsafe.Pointer(utf16(displayName))),
		0, cwUseDefault, cwUseDefault, 0, 0, 0, 0, 0, 0)
	if hwnd == 0 {
		return nil, fmt.Errorf("could not create the tray window: %w", err)
	}

	t.hwnd = windows.HWND(hwnd)
	t.iconOK = t.loadIcon("akconnect.ico", idiApplication)
	t.iconWarn = t.loadIcon("akconnect-warning.ico", idiWarning)

	t.data = notifyIconData{
		HWnd:             t.hwnd,
		UID:              1,
		UFlags:           nifMessage | nifIcon | nifTip,
		UCallbackMessage: wmTrayCallback,
		HIcon:            t.iconWarn,
	}
	t.data.setTip(displayName)

	if !notifyIcon(nimAdd, &t.data) {
		return nil, fmt.Errorf("Windows would not accept the tray icon")
	}

	return t, nil
}

// pump runs the message loop until the session ends or the customer hides the
// icon.
func (t *tray) pump() error {
	var m msg

	for {
		ret, _, _ := procGetMessage.Call(uintptr(unsafe.Pointer(&m)), 0, 0, 0)
		if int32(ret) <= 0 {
			return nil
		}

		procTranslateMessage.Call(uintptr(unsafe.Pointer(&m)))
		procDispatchMessage.Call(uintptr(unsafe.Pointer(&m)))
	}
}

// loadIcon takes the branded icon from the program folder when the pack
// shipped one, and falls back to a stock Windows icon when it did not. A tray
// with a plain icon is a tray; a tray that failed to start because an .ico was
// missing is a support call.
func (t *tray) loadIcon(name string, stock uintptr) windows.Handle {
	if dir, err := os.Executable(); err == nil {
		path := filepath.Join(filepath.Dir(dir), name)
		if _, err := os.Stat(path); err == nil {
			h, _, _ := procLoadImage.Call(0, uintptr(unsafe.Pointer(utf16(path))),
				imageIcon, 0, 0, lrLoadFromFile|lrDefaultSize)
			if h != 0 {
				return windows.Handle(h)
			}
		}
	}

	h, _, _ := procLoadIcon.Call(0, stock)

	return windows.Handle(h)
}

func wndProc(hwnd windows.HWND, message uint32, wParam, lParam uintptr) uintptr {
	t := active

	switch message {
	case wmTimer:
		if t != nil {
			t.refresh()
		}

		return 0
	case wmTrayCallback:
		// Either button opens the menu. Making the left one do nothing is a
		// convention from an era when everyone knew to right-click.
		if uint32(lParam) == wmRButtonUp || uint32(lParam) == wmLButtonUp {
			if t != nil {
				t.showMenu()
			}
		}

		return 0
	case wmCommand:
		if t != nil {
			t.command(uint16(wParam))
		}

		return 0
	case wmClose, wmDestroy:
		procPostQuitMessage.Call(0)

		return 0
	}

	ret, _, _ := procDefWindowProc.Call(uintptr(hwnd), uintptr(message), wParam, lParam)

	return ret
}

// refresh re-reads the agent's own status file and repaints the icon.
func (t *tray) refresh() {
	t.current = currentView(time.Now())

	icon := t.iconWarn
	if t.current.OK {
		icon = t.iconOK
	}

	t.data.HIcon = icon
	t.data.setTip(tooltip(t.current))

	notifyIcon(nimModify, &t.data)
}

func (t *tray) showMenu() {
	menu, _, _ := procCreatePopupMenu.Call()
	if menu == 0 {
		return
	}
	defer procDestroyMenu.Call(menu)

	// The first lines are the status, greyed out: they are what the customer
	// came to read, not something to click.
	appendItem(menu, mfString|mfGrayed|mfDisabled, 0, t.current.Headline)
	if t.current.Detail != "" {
		appendItem(menu, mfString|mfGrayed|mfDisabled, 0, "  "+t.current.Detail)
	}
	appendItem(menu, mfSeparator, 0, "")

	appendItem(menu, mfString, idOpenPanel, "Open the "+displayName+" panel")

	copyLabel := "Copy my address"
	flags := uintptr(mfString)
	if t.current.IP == "" {
		flags |= mfGrayed | mfDisabled
		copyLabel = "Copy my address (none yet)"
	} else {
		copyLabel += " (" + t.current.IP + ")"
	}
	appendItem(menu, flags, idCopyIP, copyLabel)

	appendItem(menu, mfString, idDiagnose, "Collect diagnostics for support")
	appendItem(menu, mfSeparator, 0, "")
	appendItem(menu, mfString, idExit, "Hide this icon until I sign in again")

	var pt point
	procGetCursorPos.Call(uintptr(unsafe.Pointer(&pt)))

	// Required, and the reason menus from tray icons otherwise refuse to close
	// when the customer clicks elsewhere.
	procSetForeground.Call(uintptr(t.hwnd))

	procTrackPopupMenu.Call(menu, tpmRightButton,
		uintptr(pt.X), uintptr(pt.Y), 0, uintptr(t.hwnd), 0)
}

func appendItem(menu uintptr, flags, id uintptr, text string) {
	procAppendMenu.Call(menu, flags, id, uintptr(unsafe.Pointer(utf16(text))))
}

func (t *tray) command(id uint16) {
	switch id {
	case idOpenPanel:
		t.openPanel()
	case idCopyIP:
		if err := copyToClipboard(t.current.IP); err != nil {
			t.say("Could not copy the address: "+err.Error(), mbIconError)
		}
	case idDiagnose:
		t.collectDiagnostics()
	case idExit:
		notifyIcon(nimDelete, &t.data)
		procPostQuitMessage.Call(0)
	}
}

func (t *tray) say(text string, icon uintptr) {
	procMessageBox.Call(uintptr(t.hwnd),
		uintptr(unsafe.Pointer(utf16(text))),
		uintptr(unsafe.Pointer(utf16(displayName))), icon)
}

func (t *tray) openPanel() {
	url := panelURL()
	if url == "" {
		t.say("This computer has not joined a network yet, so there is no panel to open.",
			mbIconInformation)

		return
	}

	open(url)
}

// open hands something to Windows to deal with — a URL to the browser, a
// folder to Explorer.
func open(target string) {
	procShellExecute.Call(0,
		uintptr(unsafe.Pointer(utf16("open"))),
		uintptr(unsafe.Pointer(utf16(target))),
		0, 0, swShowNormal)
}

func copyToClipboard(text string) error {
	if text == "" {
		return fmt.Errorf("there is nothing to copy")
	}

	if ret, _, err := procOpenClipboard.Call(0); ret == 0 {
		return fmt.Errorf("the clipboard is in use by another program (%w)", err)
	}
	defer procCloseClipboard.Call()

	procEmptyClipboard.Call()

	encoded := windows.StringToUTF16(text)
	size := uintptr(len(encoded) * 2)

	handle, _, err := procGlobalAlloc.Call(gmemMoveable, size)
	if handle == 0 {
		return fmt.Errorf("out of memory (%w)", err)
	}

	locked, _, _ := procGlobalLock.Call(handle)
	if locked == 0 {
		return fmt.Errorf("could not prepare the text")
	}

	// The clipboard owns the block from SetClipboardData onwards, so it is
	// neither freed nor touched here afterwards.
	copy(unsafe.Slice((*uint16)(memoryAt(locked)), len(encoded)), encoded)
	procGlobalUnlock.Call(handle)

	if ret, _, err := procSetClipboardData.Call(cfUnicodeText, handle); ret == 0 {
		return fmt.Errorf("Windows would not take the text (%w)", err)
	}

	return nil
}

// memoryAt turns an address that came back from a Windows call into something
// Go can write through.
//
// go vet objects to uintptr-to-pointer conversions, and it is right to in
// general: a Go address held as an integer is an address the garbage collector
// may move out from under you. This one is not. GlobalAlloc's block lives
// outside the Go heap, is pinned by GlobalLock for as long as it is written,
// and is handed to the clipboard immediately afterwards — the case the rule
// exists to catch cannot arise here.
func memoryAt(address uintptr) unsafe.Pointer {
	return *(*unsafe.Pointer)(unsafe.Pointer(&address))
}
