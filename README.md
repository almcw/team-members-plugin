# Team Members Display

A WordPress plugin that renders a responsive, accessible team grid with bio modals and optional video support. Drop in the `[team_grid]` shortcode wherever you want the grid to appear.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) (free or Pro) — optional but recommended. Without ACF the plugin reads the same field names directly from post meta.

## Installation

1. Upload `team-members-display.php` to `/wp-content/plugins/team-members-display/`.
2. Activate the plugin in **Plugins > Installed Plugins**.
3. The plugin registers a `team_member` post type and a `team` taxonomy automatically.

## Post type & fields

Create entries under **Team Members** in the WordPress admin. Each post supports:

| Field | Source | Notes |
|---|---|---|
| Name | Post title | |
| Photo | Featured image | Falls back to a coloured initials circle if absent |
| Job title | ACF / post meta: `job_title` | |
| Short bio | ACF / post meta: `short_bio` | Plain textarea; full text shown in modal |
| Team(s) | `team` taxonomy | Multi-select; shown as pill tags |
| Display order | ACF / post meta: `display_order` | Integer; lower = earlier. Default 5000 |
| Video URL | ACF / post meta: `video_url` | YouTube or Vimeo; clicking the card opens a video player instead of the bio modal |

## Shortcode

```
[team_grid]
```

### Attributes

| Attribute | Default | Description |
|---|---|---|
| `team` | *(all)* | Comma-separated `team` taxonomy slugs to filter by |
| `columns` | `3` | Number of columns on desktop (2–8) |
| `aspect_ratio` | `square` | `square` (1:1) or `portrait` (16:9) card images |
| `show_details` | `true` | Set to `false` to hide bios and team tags |
| `mobile_carousel` | `false` | Set to `true` to show a one-at-a-time carousel on screens under 768 px instead of a stacked grid; desktop layout is unaffected |

### Examples

```
[team_grid]
[team_grid columns="4"]
[team_grid team="leadership,engineering" columns="2" aspect_ratio="portrait"]
[team_grid show_details="false"]
[team_grid mobile_carousel="true"]
[team_grid mobile_carousel="true" columns="4" team="leadership"]
```

## Behaviour

- **Responsive layout** — 1 column on mobile, 2 on tablet, `[columns]` on desktop.
- **Bio modal** — clicking any card opens a full-screen modal with photo, name, job title, bio, and team tags. Closes on the Close button, overlay click, or Escape.
- **Video modal** — cards with a `video_url` show a play-button overlay; clicking opens a 16:9 iframe player (YouTube/Vimeo autoplay). Video stops on close.
- **Accessibility** — ARIA roles, `aria-modal`, focus trapping, keyboard activation (Enter/Space), `prefers-reduced-motion` support, and WCAG AA colour contrast throughout.
- **Performance** — CSS and modal HTML are only injected on pages that actually use the shortcode.

## License

GPL v2 or later.
