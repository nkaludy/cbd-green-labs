# CBD Green Labs — Category Icons

Simple line icons for the "Shop by category" section. Cream stroke (`#F6F2E9`) on a
forest-green circle (`#2F4A3C`), matching the homepage mockup.

## Category -> file mapping

| Category label       | File                    |
|----------------------|-------------------------|
| Flower               | `flower.svg`            |
| Vapes                | `vapes.svg`             |
| Pre-Rolls            | `pre-rolls.svg`         |
| Gummies              | `gummies.svg`           |
| Edibles              | `edibles.svg`           |
| Beverages            | `beverages.svg`         |
| Oils & Tinctures     | `oils-tinctures.svg`    |
| Topicals             | `topicals.svg`          |
| Accessories          | `accessories.svg`       |
| Concentrates         | `concentrates.svg`      |
| Pet                  | `pet.svg`               |
| Capsules & Tablets   | `capsules-tablets.svg`  |
| Other                | `other.svg`             |

Each file is a self-contained 78x78 SVG: forest-green circle + cream glyph baked in.
Drop them straight into the category nav as `<img>` sources.

## Usage (React / Claude Code)

```jsx
const CATEGORIES = [
  { slug: "flower",           label: "Flower" },
  { slug: "vapes",            label: "Vapes" },
  { slug: "pre-rolls",        label: "Pre-Rolls" },
  { slug: "gummies",          label: "Gummies" },
  { slug: "edibles",          label: "Edibles" },
  { slug: "beverages",        label: "Beverages" },
  { slug: "oils-tinctures",   label: "Oils & Tinctures" },
  { slug: "topicals",         label: "Topicals" },
  { slug: "accessories",      label: "Accessories" },
  { slug: "concentrates",     label: "Concentrates" },
  { slug: "pet",              label: "Pet" },
  { slug: "capsules-tablets", label: "Capsules & Tablets" },
  { slug: "other",            label: "Other" },
];

function CategoryNav() {
  return (
    <ul style={{ display: "grid", gridTemplateColumns: "repeat(7, 1fr)", gap: "20px 12px", listStyle: "none", padding: 0 }}>
      {CATEGORIES.map(({ slug, label }) => (
        <li key={slug}>
          <a href={`/shop/${slug}`} style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 12, textDecoration: "none" }}>
            <img src={`/icons/${slug}.svg`} width={78} height={78} alt="" />
            <span style={{ fontFamily: "Archivo, sans-serif", fontWeight: 600, fontSize: 14, color: "#2F4A3C", textAlign: "center" }}>
              {label}
            </span>
          </a>
        </li>
      ))}
    </ul>
  );
}
```

## Palette
- Circle background: `#2F4A3C` (forest) - hover `#26302A`
- Glyph / cream: `#F6F2E9`
- Label text: `#2F4A3C`
- Type: Archivo (600) for labels
