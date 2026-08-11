<!--
URL Image Importer — Support Documentation
Each section below is written as its own standalone article, separated by a horizontal rule.
Split on the "---" dividers to publish each as an individual doc.
-->

# URL Image Importer

> Import images from URLs, CSV files, or WordPress XML exports directly into your WordPress Media Library — no downloading, no FTP, no manual uploads.

[URL Image Importer](https://wordpress.org/plugins/url-image-importer/) lets you add images to your WordPress Media Library straight from a web link. Instead of downloading a file to your computer and re-uploading it through WordPress, you paste the image URL (or upload a list of them) and the plugin fetches, validates, and saves each image for you. It's the fastest way to pull external assets into your site so they're ready to use in posts, pages, and anywhere else WordPress media is available.

URL Image Importer is free, works on any host or server, and is built and maintained by the cloud architects at [Infinite Uploads](https://infiniteuploads.com/). You'll find it in your dashboard under **Media → Import Image from URL**, organized into tabs for each import method.

**What URL Image Importer can do:**

- Import any image directly into your Media Library from a public URL — no file uploads required.
- Bulk import multiple images at once from a **CSV spreadsheet** of image URLs.
- Import public image files from **Google Drive** share links, in both the URL and CSV import tools.
- Import images from a **WordPress XML export** to restore lost media or migrate between sites.
- Download a **URL mapping CSV** (Old URL → New URL) after a batch import, perfect for find-and-replace across your content.
- Analyze your storage with the built-in **Uploads Disk Utility**.
- Works seamlessly with [Big File Uploads](https://wordpress.org/plugins/tuxedo-big-file-uploads/) and [Infinite Uploads](https://infiniteuploads.com/) cloud storage.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# Which File Types Can I Import?

> URL Image Importer supports the same image formats WordPress does, including JPG, PNG, GIF, WebP, ICO, and SVG.

URL Image Importer works with the same image formats WordPress supports:

- JPEG / JPG (`.jpeg`, `.jpg`)
- PNG (`.png`)
- GIF (`.gif`)
- ICO (`.ico`)
- WebP (`.webp`) — WordPress 5.8 and later
- SVG (`.svg`) — sanitized on import for security

Video files such as MP4 are not currently supported. The plugin imports image files only.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# How Do I Import a Single Image From a URL?

> Paste a public image link into the URL Import tab and the plugin fetches, validates, and saves it to your Media Library.

Open the **URL Import** tab and paste a publicly accessible image link — one that points directly to a supported image file, such as `https://example.com/photo.jpg`. To bring in several at once, paste each URL on its own line. When you import, the plugin fetches each image, confirms it's a genuine image file, and adds it to your Media Library with a link to edit each new file.

Images are validated by their actual file contents, not just the link or extension. If a URL doesn't resolve to a real, supported image, it's safely skipped rather than imported — so a bad link in your list won't break the rest of the batch.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# Can I Import a Lot of Images at Once?

> Yes — use the CSV Import tab to bulk import hundreds or thousands of image URLs from a spreadsheet without timing out.

For large jobs — migrating an asset library, importing hundreds of product images, or pulling in a spreadsheet of links — use the **CSV Import** tab. Prepare a CSV with your image URLs in a column (optional metadata can sit alongside each URL), upload it, and the plugin reads each row, validates the image, and imports every valid file.

If you need a starting point, the **Download Sample CSV** link gives you a ready-made template. Imports are processed one at a time and recursively, so even very large lists won't time out.

**Note:** On dedicated, high-speed servers, running imports in chunks of 500–2,000 URLs per run gives the best balance of speed and reliability.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# Can I Import Images From Google Drive?

> Yes — public Google Drive image files work in both the URL and CSV importers, with no API keys or sign-in required.

Public Google Drive image files work in both the URL and CSV importers, with no API keys, credentials, or OAuth setup required. The file's sharing just needs to be set so that **anyone with the link** can view it. Paste the share link into the URL importer or include it as a row in your CSV, and the plugin downloads the file and verifies its contents before adding it to your Media Library.

**Note:** Google Drive folders, private files, videos, and Google Workspace documents (Docs, Sheets, Slides, Forms) are skipped rather than imported. The link must point to a single, public image that can be downloaded without signing in.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# Can I Import Images From a WordPress Export?

> Yes — the WordPress XML Import tab pulls every image attachment straight from a standard WordPress export file.

If you're restoring media or moving content between sites, the **WordPress XML Import** tab pulls images straight from a standard WordPress export file (the one you get from **Tools → Export** on your source site). Upload the XML file and the importer parses it, locates every image attachment, downloads it, and adds it to your Media Library. It's an easy way to recover lost media or transfer images during a migration.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# How Do I Update Old Image Links After Importing?

> After any batch import, download the URL mapping CSV to find-and-replace old image links across your content.

After any batch import, click **Download URL Mapping CSV**. You'll get a spreadsheet that pairs each original web URL with its new location in your Media Library, listed as **Old URL → New URL**.

This is especially handy when replacing externally hosted images: run a find-and-replace in your database using the mapping file, and every old image link across your posts updates to point at your local Media Library copy.

**Note:** Only users with permission to upload media can download the mapping file. Partial mapping files are cleaned up automatically if an import is canceled.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# Why Do My Imported Images Have a Different Title Than the Filename?

> By default, imported images use the filename without its extension as the title and slug — just like a manual WordPress upload.

By default, imported images use the filename **without** its extension as the attachment title and URL slug — exactly the way WordPress names a file you upload manually (so `mountain-view.jpg` becomes the title "mountain-view").

If you'd rather keep the full filename including the extension, uncheck the **Use filenames without extensions** option on the import screen before importing. This applies to URL, CSV, and WordPress XML imports.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# How Large of a File Can I Import?

> As large as your maximum upload size allows — and you can raise that limit with Big File Uploads.

Images can be as large as your maximum upload size allows, or as much as your server can support. URL Image Importer is fully compatible with [Big File Uploads](https://wordpress.org/plugins/tuxedo-big-file-uploads/), which lets you bypass the upload size limits your host sets on your server. With it installed, you can import images as large as your server allows.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# How Do I See What's Taking Up Space in My Media Library?

> The built-in Disk Utility breaks down your uploads directory by file type and size.

Open the **Disk Utility** tab for a breakdown of everything in your uploads directory by file type and size. It shows how many images, videos, archives, documents, code files, and other files (like audio) you have — and how much space each category is using. It's a quick way to understand what's taking up room before or after a large import.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).

---

# Is Infinite Uploads Required to Use the Plugin?

> No — URL Image Importer works on its own. Infinite Uploads is optional cloud storage for sites that need to scale.

URL Image Importer works on its own with any host or server. [Infinite Uploads](https://infiniteuploads.com/) is an optional cloud storage and CDN service — if your Media Library is growing quickly, you can connect an account to import and store files of any size off-server. Moving your uploads directory to the cloud keeps your site fast, lowers storage and bandwidth costs, and makes your Media Library infinitely scalable. It's there if and when you need it.

Need a hand? Our support team is happy to help — just [open a ticket](https://infiniteuploads.com/).
