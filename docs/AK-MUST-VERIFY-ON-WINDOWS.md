# AK must verify on Windows — 1.9.5

Click-only. No PowerShell, no command prompt, nothing to type except a join
code. Two Windows 10/11 PCs on different internet connections, one of them
behind a mobile or CGNAT line, and for §3 both PCs on the same router.

Everything below was built and tested as far as a Linux lab can take it. None
of it has run on Windows. A green lab is not proof; these two PCs are.

Tick each line. Anything that does not behave as written is a defect — send me
the line number and what happened instead.

---

## 1. Install (PC 1)

1. Open the link I send you. Click **Download for Windows**.
2. Double-click the downloaded file. Click **Yes** to Windows' prompt.
3. Type the join code, click **OK**.
4. Within about a minute: a dialog says it is installed and running.
5. Look at the bottom-right of the screen (the arrow next to the clock).
   There is an **AK Connect** icon. Hover it: it says Connected, or says it is
   waiting for approval.

## 2. Install (PC 2) — the acceptance test

6. Same three steps on the second PC, on a different internet connection.
7. In the panel, approve both if they are waiting.
8. Within 60 seconds of approving, both show **Online** in the panel.
9. Right-click the tray icon on PC 1 → **Copy my address**. It copies a
   10.50.x.x address.
10. Right-click on PC 2 → the menu shows the OTHER PC's count ("1 of 1
    computers reachable").
11. On PC 2: Start → type `cmd` → `ping <PC 1's 10.50 address>`. It replies.
    *(The one command in this list. If you would rather not, open
    `\\10.50.x.x\` in File Explorer instead — it should prompt or open.)*

## 3. Two PCs behind one router — defect 23

12. Put BOTH PCs on the same Wi-Fi/router (the shop case).
13. Wait a minute. Both still **Online** in the panel.
14. Each one can still reach the other's 10.50 address.
15. On the device pages, the two **Public endpoint** values show different port
    numbers after the colon. *(This is the fix: they no longer both use 51820.)*

## 4. It keeps working — defect 30 and the reliability list

16. Restart PC 1. Sign in. Do nothing else. Within a minute of the desktop
    appearing it is **Online** again and the tray icon says Connected.
17. Close the laptop lid (or Start → Sleep) for two minutes. Open it. Within
    30 seconds: **Online**, and the other PC is reachable.
18. Switch PC 1 from Wi-Fi to a cable, or to a phone hotspot. Within 30
    seconds: **Online** again, no clicking.
19. Unplug the router for a minute and plug it back in. Within a minute of the
    internet returning, both PCs are **Online**.
20. Leave both PCs on overnight. Next morning, both still **Online**.

## 5. The panel says what is true

21. Open a device's page. **Agent running since** shows a sensible time, and
    it resets after the restart in step 16.
22. **Last problem** says "none reported", or names something real.
23. **Public endpoint** matches where that PC actually is after step 18.
24. Click **Delete** on a device: a confirmation dialog appears with Cancel
    under the finger, not a browser pop-up.

## 6. Share a LAN — if you have a camera recorder or a printer to reach

25. On the PC that is on the same network as it: device page → **Share this
    computer's network**. The range is already filled in. Check it matches the
    router, click **Share this network**.
26. The page lists it as **live** and shows the address the others use.
27. From the OTHER PC, open that address in a browser. The camera recorder's
    page loads.

## 7. Update

28. Publish 1.9.5 on the panel, then on a device page click **Update now**.
29. Within a couple of minutes the **Agent version** on that page reads 1.9.5,
    without anybody touching that PC.
30. It is still Online afterwards, and step 11 still works.

## 8. Uninstall — leaves nothing behind

31. On PC 2: Windows **Settings → Apps → Installed apps**. **AK Connect** is
    in the list, with a version and "AK Computer" as publisher.
32. Click **Uninstall**. Confirm.
33. It finishes and shows a list of what it removed.
34. Settings → Network & internet → Advanced → Network adapters: there is no
    AKConnect adapter left.
35. The Start menu has no AK Connect folder, and the tray icon is gone.
36. `C:\Program Files\AKConnect` no longer exists (File Explorer).
37. Restart PC 2. Nothing AKConnect starts, and Settings → Apps still does not
    list it.

## 9. Upgrade keeps the same computer — do this last

38. Reinstall on PC 2 with the same join code (or a new one).
39. In the panel it is the SAME device row as before if you did not delete it —
    same 10.50 address. If you did delete it, it appears as a new one and
    connects.
40. Run the installer AGAIN over the top. It upgrades in place, keeps the
    address, and does not ask for a code a second time.

---

## If something goes wrong

Right-click the tray icon → **Collect diagnostics for support**. It puts one
zip on the Desktop. Send me that file and the number of the line above that
failed. It contains no passwords and no keys — the collector strips them.

## What I could not test for you, and why it is on this list

- Every dialog, the Apps & features entry, the Start menu shortcut, the tray
  icon, the clipboard and the diagnostics bundle: all Windows API calls. They
  build and their logic is unit-tested; none has been seen on a screen.
- The MSI (`msiexec /i AKConnect.msi JOINCODE=... /qn`). Its tables are read
  back and checked after every build; msiexec has never run it.
- Sleep, hibernation, Fast Startup and network-change events: the agent asks
  Windows to send them and acts on them. The lab proves the acting-on; only a
  real PC proves Windows sends them.
- Gateway mode on Windows (§6). The NAT commands are the documented ones and a
  real bug in them was fixed this release, but none of it has run on Windows.
