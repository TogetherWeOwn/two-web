{{--
    Shared per-field form error (TOG-8420). Renders one field's inline error
    with a polite live region so screen-reader users hear it without
    interrupting the error summary's assertive announcement — the summary
    keeps role="alert" plus the focus move (TOG-6957); this partial only
    announces its own line when Livewire morphs it in.

    Expected variables: $id (the error element id referenced by the input's
    aria-describedby) and $message (the validation message).
--}}
<p id="{{ $id }}" class="mt-1.5 flex items-start gap-1.5 text-sm text-alert" aria-live="polite">
    <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1.5 15 14H1L8 1.5Zm0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5.5Zm0 6.75a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Z"/></svg>
    <span>{{ $message }}</span>
</p>
