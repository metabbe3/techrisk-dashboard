# Frontend Engineering Standards (Reference)

Detailed code patterns and examples for frontend development. Read this when implementing UI features.

---

## TailwindCSS Configuration

Custom configuration in `tailwind.config.js`. Use `@source` directive for automatic scanning.

```javascript
export default {
  content: ['./resources/**/*.blade.php'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Instrument Sans', 'sans-serif'],
      },
      colors: {
        primary: { /* custom colors */ },
        danger: { /* custom colors */ },
      },
    },
  },
};
```

## Custom Styles

Keep custom CSS minimal - prefer Tailwind utilities. Use `@layer` directive for extending components.

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer components {
  .btn-primary {
    @apply bg-primary-600 text-white px-4 py-2 rounded hover:bg-primary-700;
  }
}
```

Custom styles stored in `resources/css/app.css`.

## Filament Theming

Custom panel config in `AdminPanelProvider.php`:

```php
Panel::make()
    ->brandLogo(asset('images/logo.png'))
    ->brandName('Technical Risk Dashboard')
    ->colors([
        'primary' => Color::Amber,
    ])
    ->sidebarCollapsibleOnDesktop();
```

## JavaScript Standards

- Use Vite for all asset compilation
- Import dependencies via ES modules
- Use Laravel Vite plugin

```javascript
import { createInertiaApp } from '@inertiajs/inertia-vue3';
import '../css/app.css';
```

### Alpine.js Usage (if needed)

- Use for lightweight interactivity
- Keep logic in `x-data` attributes
- For complex state, use Livewire or create custom Filament widgets

## Performance Best Practices

- Enable **Vite HMR** in development
- Minimize CSS by purging unused Tailwind classes (automatic in production)
- Lazy load heavy JavaScript modules
- Use `vite build --minify` for production
