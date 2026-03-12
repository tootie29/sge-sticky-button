# SGE Sticky Button

A lightweight WordPress plugin that adds a fully customizable sticky button to any page or post. Configure the text, URL, colors, typography, position, and exactly where it appears — all from the WordPress admin.

---

## Requirements

- WordPress **5.0** or higher
- PHP **7.4** or higher
- No additional plugins or libraries required

---

## Installation

1. Download or clone this repository into your WordPress plugins directory:
   ```
   wp-content/plugins/sge-sticky-button/
   ```
2. In your WordPress admin, go to **Plugins → Installed Plugins**.
3. Find **SGE Sticky Button** and click **Activate**.

---

## Global Settings

Navigate to **Settings → SGE Sticky Button** to configure the plugin.

### Content

| Field | Description |
|---|---|
| **Button Text** | The label displayed on the button. |
| **Button URL** | The link the button points to. Supports any URL or a hash anchor (e.g. `#contact`). |
| **Open in New Tab** | When checked, the link opens in a new browser tab. |

### Style

| Field | Default | Description |
|---|---|---|
| **Background Color** | `#00CAF3` | Pick a color or type a hex value. |
| **Text Color** | `#00313E` | Pick a color or type a hex value. |
| **Font Size** | `16px` | Accepts any CSS unit — `px`, `em`, `rem`, `vw`, etc. |
| **Line Height** | `1.4` | Unitless (e.g. `1.4`) or with unit (e.g. `24px`). |

> The button inherits the **font family** from your active theme automatically.

### Position

| Option | Behavior |
|---|---|
| **Bottom Right** | Flush to the right edge of the viewport, 30px from the bottom. Pill shape on the left side. |
| **Bottom Left** | Flush to the left edge of the viewport, 30px from the bottom. Pill shape on the right side. |
| **Custom** | Manually set `bottom`, `right`, and `left` using any CSS unit. Full pill shape. |

> **Bottom Right / Left** presets render as an edge-anchored tab — rounded on the inner side, flat against the viewport edge. Hovering pulls the button slightly away from the edge.

### Display Rules

| Option | Behavior |
|---|---|
| **All pages & posts** | The button appears on every page and post sitewide. |
| **Specific pages / posts** | Enter comma-separated post/page IDs (e.g. `12, 45, 100`). |

A collapsible reference table is provided in the settings to help you look up IDs for all published pages and posts.

---

## Per-Post Override (Meta Box)

Every post editor — including custom post types — has a **Sticky Button** panel in the sidebar.

### Enable Sticky Button

Checking **Enable Sticky Button** on a specific post will:

- Force the button to appear on that post **regardless of the global Display Rules**.
- This is useful for custom post types or posts not listed in the global specific IDs.

When enabled, two optional override fields appear:

| Field | Description |
|---|---|
| **Button Text** | Overrides the global button text for this post only. Leave blank to use the global value. |
| **Button URL** | Overrides the global button URL for this post only. Leave blank to use the global value. |

### Override Priority

```
Per-post override (if filled)
    └── Falls back to Global Settings
```

---

## Smooth Scroll for Hash Links

If the button URL is a **hash anchor** (e.g. `#contact`, `#booking-form`), the plugin automatically intercepts the click and **smoothly scrolls** to the target element on the page instead of jumping.

- The target element must have a matching `id` attribute (e.g. `<section id="contact">`).
- If the target element is not found on the page, the default browser behavior runs as a fallback.
- The URL hash is updated via `history.pushState` without causing a page jump.

**Example:** Setting the URL to `#booking-form` will smoothly scroll down to the element with `id="booking-form"` on the current page.

---

## Tutorial: Setting Up Your First Sticky Button

### Step 1 — Configure global settings

1. Go to **Settings → SGE Sticky Button**.
2. Set your **Button Text** (e.g. `Book Consultation`).
3. Set your **Button URL** (e.g. `https://yoursite.com/contact` or `#contact`).
4. Choose your **Background Color** and **Text Color**.
5. Set the **Position** to **Bottom Right** (default).
6. Under **Display Rules**, choose **All pages & posts** or pick **Specific pages / posts** and enter their IDs.
7. Click **Save Changes**.

### Step 2 — Enable on a specific post (optional)

1. Open any page, post, or custom post type in the editor.
2. Find the **Sticky Button** panel in the right sidebar.
3. Check **Enable Sticky Button**.
4. Optionally enter a different **Button Text** and/or **Button URL** just for this post.
5. Publish or update the post.

### Step 3 — Smooth scroll (optional)

1. On your page, add an `id` to the section you want to scroll to:
   ```html
   <section id="contact">...</section>
   ```
2. In the button URL (global or per-post override), enter `#contact`.
3. Save. The button will now smoothly scroll to that section when clicked.

---

## File Structure

```
sge-sticky-button/
└── sge-sticky-button.php   — Main plugin file (all logic is self-contained)
```

---

## Changelog

### 1.3.0
- Added smooth scroll for hash/anchor links
- Edge-anchored tab shape for Bottom Right / Bottom Left presets (right: 0 / left: 0)
- Hover animation pulls away from the anchored edge

### 1.2.0
- Added per-post meta box with Enable toggle and URL/text overrides
- Per-post enable bypasses global display rules

### 1.1.0
- Added style options: background color, text color, font size, line height
- Font family inherits from theme
- Color picker with synced hex input field

### 1.0.0
- Initial release
- Sticky button with text, URL, position, and display rules
