# Reel Practice — Mac v2

A local full-stack Mac application for turning saved guitar Reels/videos into a usable lesson library.

## What works now

- Real FastAPI backend and SQLite database.
- Import local video files.
- Import an Instagram/TikTok/YouTube URL using `yt-dlp`.
- Optional use of your Chrome/Safari/Firefox login session for Instagram URLs.
- If a URL cannot be downloaded, the source is still saved and you can attach the video manually.
- Automatic scan of the **entire video** for frames that visually resemble guitar TAB.
- The TAB scanner only captures source frames; it does **not** invent or transcribe missing notes.
- Manual source-TAB frame capture at the current playhead position.
- A–B loop without speed changes.
- Search and filters.
- Delete → Recently Deleted → Restore / Delete forever.
- Notes per lesson.
- All data stays in the `data` folder on your Mac.

## First launch

1. Unzip the folder somewhere permanent, for example `Applications/Reel Practice` or your Documents folder.
2. Double-click **install.command** once.
3. If macOS blocks it: Control-click → Open.
4. When installation is finished, double-click **start.command**.
5. Chrome/Safari opens `http://127.0.0.1:8787`.

Do **not** open `static/index.html` directly. The backend is required.

## Importing a Reel

Paste a full Reel URL into the large field at the top of Library and click **Import**.

If Instagram allows access, the video is downloaded, indexed, thumbnailed, and automatically scanned for source TAB.

If it does not, the item appears in **Pending imports**. Click **Attach video** and select the corresponding video file.

## Instagram login

Open **Settings** and choose the browser in which you are already logged into Instagram. Chrome is the default. Reel Practice asks `yt-dlp` to reuse that local browser session. Instagram can still block particular content; manual attach remains the safe fallback.

## Source TAB

After a video is ready, Reel Practice scans the full timeline for likely TAB frames. Detected source frames appear inside the lesson. Clicking one jumps the video to that time.

Because false positives are possible, every capture can be deleted. Use **Analyze entire video** to rescan. Use **Capture current frame** if the detector missed a real TAB frame.

## Data and backup

All personal data is inside:

`data/reel_practice.sqlite3`
`data/videos/`
`data/thumbs/`
`data/tabs/`

Back up the entire `data` folder to preserve your library.

## Stop the app

Return to the Terminal window opened by `start.command` and press **Control+C**.
