@php
    $editorId = 'wysiwyg_' . $field;
@endphp
<div wire:ignore class="border rounded-3 overflow-hidden bg-white shadow-sm">
    <div class="bg-light border-bottom p-2 d-flex flex-wrap align-items-center gap-1">
        <select
            class="form-select form-select-sm w-auto"
            title="Taille d'écriture"
            onchange="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'fontSize', this.value); this.selectedIndex = 0;"
        >
            <option value="">Taille...</option>
            <option value="2">Petit</option>
            <option value="3">Normal</option>
            <option value="4">Moyen</option>
            <option value="5">Grand</option>
            <option value="6">Très grand</option>
        </select>

        <button type="button" class="btn btn-outline-secondary btn-sm" title="Gras" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'bold')">
            <i class="bi bi-type-bold"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" title="Italique" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'italic')">
            <i class="bi bi-type-italic"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" title="Souligné" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'underline')">
            <i class="bi bi-type-underline"></i>
        </button>

        <div class="vr mx-1"></div>

        <button type="button" class="btn btn-outline-secondary btn-sm" title="Liste à puces" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'insertUnorderedList')">
            <i class="bi bi-list-ul"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" title="Liste numérotée" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'insertOrderedList')">
            <i class="bi bi-list-ol"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" title="Citation" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'formatBlock', 'blockquote')">
            <i class="bi bi-quote"></i>
        </button>

        <div class="vr mx-1"></div>

        <button type="button" class="btn btn-outline-secondary btn-sm" title="Insérer un lien" onclick="adminWysiwygLink('{{ $editorId }}', '{{ $field }}')">
            <i class="bi bi-link-45deg"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" title="Insérer un tableau" onclick="adminWysiwygTable('{{ $editorId }}', '{{ $field }}')">
            <i class="bi bi-table"></i>
        </button>

        <div class="vr mx-1"></div>

        <button type="button" class="btn btn-outline-secondary btn-sm" title="Aligner à gauche" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'justifyLeft')">
            <i class="bi bi-text-left"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" title="Centrer" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'justifyCenter')">
            <i class="bi bi-text-center"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" title="Effacer la mise en forme" onclick="adminWysiwygExec('{{ $editorId }}', '{{ $field }}', 'removeFormat')">
            <i class="bi bi-eraser"></i>
        </button>
    </div>

    <div
        id="{{ $editorId }}"
        contenteditable="true"
        class="p-3"
        style="min-height: 320px; outline: none; line-height: 1.7;"
        oninput="adminWysiwygSync('{{ $editorId }}', '{{ $field }}')"
    >{!! $value !!}</div>
</div>
