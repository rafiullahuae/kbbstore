# Occupations (Work Abroad)

The owner, 16 Nov 2026: *"not just for study abroad, i need work abroad also, for different occupations ... focus more
on the images, specially the pictures of the workers, employees in specific uniform etc."*

`occupations.json` is the one list both studios use: 100 occupations in 10 sectors, each with

| field | what |
|---|---|
| `name`, `title` | English and Arabic (`title` = the short form for headlines) |
| `inSentence` | "a welder" / "an electrician" -- for "Work in Poland as a welder" |
| `uniform`, `ppe` | what the worker wears (the photo search, the AI prompt and the placeholder figure all follow it) |
| `tools` | three tools, English + Arabic name, an icon (`icons/<id>.svg`, one colour `#0b2545`, recoloured by the apps) |
| `colors` | uniform / trim / PPE colours (the placeholder figure and the ad accents) |
| `figure` | the placeholder figure: family + parts (`figures.py`); `figures/<family>.svg` is a neutral copy |
| `keywords` | English and Arabic, for matching a reel or a photo to the occupation |
| `photo.search` | the Pexels / Unsplash search terms behind the Photos tab's quick filter |
| `prompt` | the prompt template for the local image model (`{person}`, `{region}`, `{framing}`) |
| `countries` | **suggestions** to start from (codes from the 33-country pack). Edit them to the routes you handle -- they never say a country is recruiting |

There is deliberately **no salary, visa type, contract length or benefit** anywhere in this data. The Ad Studio's Work
Abroad designs show those as `‹edit›` boxes with "Requirements and terms confirmed at consultation".

```bash
python3 build-occupations.py      # rewrites occupations.json, icons/*.svg, figures/*.svg
```

Edit the `O(...)` lines in `build-occupations.py` (or `occupations.json` directly if you never rebuild). The icons
(`icons.py`) and the figures (`figures.py`) are drawn for KBB Studio -- original work, free to use. Real worker photos
are not bundled (licences): find them with the Photos tab (Pexels / Unsplash with your own free keys, saved into
`library/people/<occupation>/` with the photographer and licence beside each one), use your own photos, or the local
image model later.
