# Storefront store and network images

A logo and a backdrop per seeded store and network, named after the key in `StoreSeeder.php` /
`NetworkSeeder.php`. `SeedsStorefrontFixtures::attachStoreImages()` copies them onto the
public disk and sets them as the store's (or network's) `logo_uuid` and `backdrop_uuid`; one
without a file here keeps Storefront's placeholder.

Product categories have no files of their own: `attachCategoryImages()` gives each the photo of
its first product (see `../products/`).

**Logos** (`<key>-logo.png`, 512×512) are generated: the store's initials in a ring on a solid
colour. They are not real brands.

**Backdrops** (`<key>-backdrop.jpg`) are from [Unsplash](https://unsplash.com) under the
[Unsplash License](https://unsplash.com/license): free for commercial use, no permission or
attribution required. Unsplash+ photos are excluded. Fetched center-cropped at 1200×675
(`?w=1200&h=675&fit=crop&crop=entropy&q=78&fm=jpg`). Keep replacements free of legible brands,
shop signs and recognisable faces. `fleetbase-marketplace-backdrop.jpg` is darkened to 60%
brightness: the network's name and description sit on it in white.

| File | Photo | Photographer |
|---|---|---|
| `fleetbase-market-backdrop.jpg` | [ZpIskW1Tuvc](https://unsplash.com/photos/ZpIskW1Tuvc) | [Raul Gonzalez Escobar](https://unsplash.com/@ragonesco) |
| `orchard-grocers-backdrop.jpg` | [stpjHJGqZyw](https://unsplash.com/photos/stpjHJGqZyw) | [Gemma C](https://unsplash.com/@_gemmajade) |
| `rochor-noodle-house-backdrop.jpg` | [nIiuEQJlZs4](https://unsplash.com/photos/nIiuEQJlZs4) | [Luna Wang](https://unsplash.com/@lunawangjl) |
| `tampines-fresh-mart-backdrop.jpg` | [-gOUx23DNks](https://unsplash.com/photos/-gOUx23DNks) | [Jacopo Maiarelli](https://unsplash.com/@ja_ma) |
| `bayfront-pharmacy-backdrop.jpg` | [PjmNoFo6Y0s](https://unsplash.com/photos/PjmNoFo6Y0s) | [Barnaby Woodrow](https://unsplash.com/@cranky_bear) |
| `marina-flowers-backdrop.jpg` | [D4Blldtly00](https://unsplash.com/photos/D4Blldtly00) | [Alisa Anton](https://unsplash.com/@alisaanton) |
| `fleetbase-marketplace-backdrop.jpg` | [RRC9KMMMneY](https://unsplash.com/photos/RRC9KMMMneY) | [Scribbling Geek](https://unsplash.com/@scribblinggeek) |
