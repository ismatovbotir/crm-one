<?php

namespace App\Livewire\Concerns;

/**
 * Standard document page tabs (user decision 2026-10-20): Header | Items | Other, plus
 * document-specific tabs where needed (an offer preview, an agreement document). The summary
 * (number, status, partner, totals, next action) stays above the tabs on every document.
 *
 * The component declares the URL-persisted property itself, so each document picks its default:
 *
 *     #[Url(except: 'items')]
 *     public string $tab = 'items';
 *
 * and renders `<x-tabs :tabs="$this->documentTabList()" :active="$tab" prefix="doc" ...>`. Only the
 * active panel is rendered (heavy tabs load when opened); all tabs share one component state, so
 * unsaved header inputs survive a tab switch. Unknown or legacy values fall back here.
 */
trait HasDocumentTabs
{
    /**
     * Visible tabs for the current user: key => label, or key => ['label' => .., 'count' => ?int,
     * 'marker' => ?string].
     *
     * @return array<string, string|array{label: string, count?: int|null, marker?: string|null}>
     */
    abstract protected function documentTabs(): array;

    protected function defaultDocumentTab(): string
    {
        return 'items';
    }

    /**
     * Old tab names still found in bookmarks and links (old => new).
     *
     * @return array<string, string>
     */
    protected function documentTabAliases(): array
    {
        return [];
    }

    /**
     * @return array<string, string|array{label: string, count?: int|null, marker?: string|null}>
     */
    public function documentTabList(): array
    {
        return $this->documentTabs();
    }

    public function mountHasDocumentTabs(): void
    {
        $this->normalizeDocumentTab();
    }

    public function updatedHasDocumentTabs(string $property): void
    {
        if ($property === 'tab') {
            $this->normalizeDocumentTab();
        }
    }

    protected function normalizeDocumentTab(): void
    {
        $tab = $this->documentTabAliases()[$this->tab] ?? $this->tab;

        $this->tab = array_key_exists($tab, $this->documentTabs()) ? $tab : $this->defaultDocumentTab();
    }

    /**
     * Standard label with an optional counter.
     *
     * @return array{label: string, count: int|null, marker: string|null}
     */
    protected function documentTab(string $label, ?int $count = null, ?string $marker = null): array
    {
        return ['label' => $label, 'count' => $count, 'marker' => $marker];
    }
}
