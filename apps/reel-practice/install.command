#!/bin/bash
set -e
cd "$(dirname "$0")"
clear
echo "Reel Practice — installing backend dependencies"
command -v python3 >/dev/null || { echo "Python 3 is required"; exit 1; }
command -v ffmpeg >/dev/null || { echo "ffmpeg is missing. Install Homebrew then: brew install ffmpeg"; exit 1; }
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
echo
echo "Installed. Double-click start.command"
read -n 1 -s -r -p "Press any key to close"
