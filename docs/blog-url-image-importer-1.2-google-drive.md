---
title: "URL Image Importer 1.2: Import Images Straight From Google Drive Links"
slug: url-image-importer-1-2-google-drive-imports
date: 2026-05-27
category: Product Updates
author: Infinite Uploads
excerpt: "Version 1.2 of URL Image Importer adds public Google Drive share links to both the URL and CSV importers — no API keys, no OAuth, no downloads. Paste the link and we'll fetch, validate, and add the image to your Media Library."
---

# URL Image Importer 1.2: Import Images Straight From Google Drive Links

Your images live in a lot of places, and Google Drive is one of the most common. Up to now, getting a Drive image into WordPress meant a familiar dance: open the file, download it to your computer, then re-upload it through the Media Library. Multiply that by a few hundred product shots or a shared brand folder and it stops being a minor annoyance.

**URL Image Importer 1.2 removes that step entirely.** Paste a public Google Drive share link — or drop it into your CSV alongside everything else — and the plugin fetches the file, verifies it's a real image, and adds it to your Media Library. No API keys, no OAuth setup, no Google sign-in, no downloads.

[Download URL Image Importer free from WordPress.org →](https://wordpress.org/plugins/url-image-importer/)

## What's new in 1.2

### Google Drive links in the URL importer

Open the **URL Import** tab, paste a public Google Drive share link, and import. That's the whole workflow. The file's sharing just needs to be set so that **anyone with the link** can view it. The plugin downloads the file, confirms its contents, and saves it like any other import — complete with an edit link for the new attachment.

### Google Drive links in CSV imports, too

Migrating an asset library or pulling in a folder's worth of images? Add your Google Drive share links as rows in a CSV right next to regular image URLs and import them in one batch. Drive links don't always end in a tidy `.jpg`, so we improved CSV handling to **accept Drive share links that have no image file extension** — they're accepted in the import preview and validated during the actual import.

### Smarter, content-based validation

Drive links can point to all sorts of things, so 1.2 validates by **what the file actually is**, not what the link claims. Anything that isn't a downloadable public image is safely skipped instead of imported, including:

- Private files and Google login/permission pages
- Drive **folders**
- Videos
- Google Workspace documents — Docs, Sheets, Slides, and Forms

A bad or non-image link in your list won't break the rest of the batch. The link simply needs to point to a single, public image that can be downloaded without signing in.

### A CSV preview fix for re-imports

We also fixed CSV preview behavior for URLs you've already imported. Duplicates now flow correctly through the batch importer and the **URL Mapping CSV** export, so re-running or extending an import behaves the way you'd expect.

## Why this matters

Google Drive is where a lot of teams keep their working images — handoffs from designers, client uploads, shared brand folders, exported product galleries. Bringing those into WordPress used to mean leaving your dashboard, downloading, and uploading again, one file at a time.

With 1.2, Drive becomes just another source you can paste in or list in a spreadsheet. Combine it with the existing CSV importer and the **URL Mapping CSV** (Old URL → New URL), and you can move an entire library into your Media Library and find-and-replace the old links across your content in a single pass.

## How to get it

URL Image Importer is **free**, works on any host or server, and is built and maintained by the cloud architects at [Infinite Uploads](https://infiniteuploads.com/).

1. Install or update **URL Image Importer** from your WordPress dashboard, or [download it from WordPress.org](https://wordpress.org/plugins/url-image-importer/).
2. Go to **Media → Import Image from URL**.
3. Open the **URL Import** or **CSV Import** tab.
4. Paste your Google Drive share link (set to *anyone with the link*) and import.

### Working with large libraries

Importing thousands of files? URL Image Importer processes imports one at a time and recursively, so even very large lists won't time out. On dedicated, high-speed servers, running imports in chunks of **500–2,000 URLs per run** gives the best balance of speed and reliability. And if you bump into your host's upload size limits, [Big File Uploads](https://wordpress.org/plugins/tuxedo-big-file-uploads/) lets you bypass them.

When your Media Library starts to outgrow your server, [Infinite Uploads](https://infiniteuploads.com/) cloud storage and CDN can hold files of any size off-server — keeping your site fast while your library scales infinitely. It's optional, and it's there when you need it.

## Full 1.2 changelog

- **Added:** support for importing public Google Drive image file links from the URL importer and CSV importer.
- **Added:** content-based validation for Google Drive downloads so non-images, private/login pages, folders, videos, and Google Workspace document links are skipped instead of imported.
- **Improved:** CSV handling so Google Drive share links without image file extensions are accepted for import preview and validated during import.
- **Fixed:** CSV preview behavior for already-imported URLs so duplicates can be handled by the batch importer and URL mapping export.

---

*Questions or need a hand with a big migration? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).*
