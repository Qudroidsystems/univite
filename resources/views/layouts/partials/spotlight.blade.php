{{-- The search box (Ctrl+K / ⌘K). Pages come from the sidebar menu; records come from route('search'). See public/js/univite-shell.js. --}}
<div id="spotlight-overlay" role="dialog" aria-modal="true" aria-label="Search" style="display:none;">
    <div id="spotlight-box">
        <div class="sl-head">
            <i class="ri-search-line sl-head-icon"></i>
            <input id="spotlight-input" type="text" placeholder="Search pages, students, staff, courses, invoices…" autocomplete="off" aria-label="Search">
            <button type="button" id="spotlight-clear-history" class="sl-btn" style="display:none;">Clear history</button>
            <kbd id="spotlight-esc" class="sl-btn">ESC</kbd>
        </div>
        <div id="spotlight-results">
            <div id="spotlight-suggestions" style="display:none;">
                <div class="sl-label">Suggestions</div>
                <div id="spotlight-suggestion-chips"></div>
                <div class="sl-rule"></div>
            </div>
            <div id="spotlight-history-section" style="display:none;">
                <div class="sl-label d-flex justify-content-between"><span>Recent searches</span><button type="button" id="spotlight-clear-history-btn" class="sl-link">Clear all</button></div>
                <div id="spotlight-history-list"></div>
                <div class="sl-rule"></div>
            </div>
            <div id="spotlight-empty" class="sl-empty">
                <i class="ri-flashlight-line"></i>
                <span>Start typing to search…</span>
                <div class="sl-hint">Try a student's name or matric number, a course code, or a page such as "invoices".</div>
            </div>
            <ul id="spotlight-list" style="display:none;"></ul>
            <div id="spotlight-loading" style="display:none;"><div class="sl-spinner"></div><div class="sl-hint">Searching<span class="typing-dot">.</span><span class="typing-dot">.</span><span class="typing-dot">.</span></div></div>
        </div>
        <div class="sl-foot">
            <span><kbd>⌘K</kbd> / <kbd>Ctrl+K</kbd> open</span>
            <span><kbd>↑↓</kbd> move</span>
            <span><kbd>↵</kbd> open</span>
            <span><kbd>ESC</kbd> close</span>
        </div>
    </div>
</div>
<script type="application/json" id="spotlight-data">@json(['pages' => $searchPages ?? [], 'url' => route('search'), 'user' => auth()->id()])</script>
