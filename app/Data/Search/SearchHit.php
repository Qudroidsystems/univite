<?php

namespace App\Data\Search;

/** One result in the search box. */
final readonly class SearchHit
{
    public function __construct(
        public string $title,
        public string $subtitle,
        public string $url,
        public string $icon,
        public string $category,
    ) {
    }

    /** @return array{title: string, subtitle: string, url: string, icon: string, category: string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'subtitle' => $this->subtitle, 'url' => $this->url, 'icon' => $this->icon, 'category' => $this->category];
    }
}
