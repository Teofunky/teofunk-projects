# Reel Practice 2.7 — Source Notation + Content Backfill

This build keeps the existing local library and re-analyzes already downloaded videos in place. Existing Reels are **not downloaded again**.

## What changed in 2.7
- Re-analyzes every existing local lesson on first launch.
- Distinguishes four source types: **TAB**, **standard notation**, **fretboard/chord diagram**, and **video only**.
- Fixes the old binary TAB / No TAB logic: a fretboard diagram is no longer treated as "no notation" or as TAB.
- Scans the whole video, keeps changed source-notation fragments in chronological order, and removes near-duplicates.
- Builds one consolidated **Source TAB / Source Notation / Source Diagram sheet** from the material actually shown by the author. It does not invent notes.
- Keeps the original extracted fragments underneath the consolidated sheet so every result can be checked against the source frame and timestamp.
- OCR backfill reads text visibly present in the video and combines it with saved source metadata to create conservative Auto Tags and, when reliable, a more meaningful lesson title.
- Auto Tags, My Tags, Collections, Favorites, Practice A–B loops, Notes, deletion/restore, Instagram import and local storage remain available.
- Library filters are now **TAB / Diagrams / Notation / Video only** rather than the misleading TAB / Without TAB split.

## Important source-faithfulness rule
Reel Practice only extracts notation that is actually present in the original Reel. If there is no source TAB, it does not generate one. A chord/fretboard diagram remains a diagram.

The consolidated Source TAB is assembled from the author's actual notation fragments; the app does not guess missing fret numbers. Source fragments and timestamps remain available for verification.

## Install / update
1. Do **not** delete `~/Library/Application Support/Reel Practice/data` — that is your library.
2. Quit the previous Reel Practice app/backend.
3. Double-click **Install Reel Practice.command**.
4. The installer updates the backend/app code and installs Tesseract through Homebrew if it is missing.
5. Launch **Reel Practice.app**.
6. The browser should open `http://127.0.0.1:8797/?v=270`.

On first launch, existing downloaded videos are analyzed locally. This may use CPU for several minutes. Nothing is re-downloaded from Instagram.

## Re-analyze manually
Settings → **Re-analyze existing videos** reruns both content analysis and source-notation analysis for the existing library.

## Daily use
After installation, use **Reel Practice.app**. Terminal is not required for normal use.
