@if (! empty($gaps))
    <div class="alert alert-warning">
        <strong>The scale has gaps.</strong> Scores in these ranges would get no grade: {{ implode('; ', $gaps) }}.
    </div>
@endif
