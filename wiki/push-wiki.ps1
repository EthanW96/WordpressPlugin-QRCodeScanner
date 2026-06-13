# Pushes the wiki/ folder contents to the GitHub Wiki repo.
# Run this once from the project root after creating the wiki on GitHub.
#
# Prerequisites:
#   1. Go to https://github.com/EthanW96/WordpressPlugin-QRCodeScanner/wiki
#   2. Create any page (e.g. the Home page) to initialize the wiki repo.
#   3. Then run this script.

$wikiRepo = "https://github.com/EthanW96/WordpressPlugin-QRCodeScanner.wiki.git"
$tempDir  = "$env:TEMP\qr-tracker-wiki"

if (Test-Path $tempDir) { Remove-Item $tempDir -Recurse -Force }

git clone $wikiRepo $tempDir

# Copy all markdown files from wiki/ into the cloned wiki repo
Copy-Item "$PSScriptRoot\*.md" $tempDir -Force -Exclude "push-wiki.ps1"

Set-Location $tempDir
git add -A
git commit -m "Add full plugin documentation wiki"
git push origin master

Write-Host "Wiki pushed successfully." -ForegroundColor Green
Write-Host "View it at: https://github.com/EthanW96/WordpressPlugin-QRCodeScanner/wiki" -ForegroundColor Cyan
