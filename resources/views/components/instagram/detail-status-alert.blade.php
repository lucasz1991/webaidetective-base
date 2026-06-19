@props([
    'message' => null,
    'level' => 'neutral',
    'class' => '',
])

@if($message)
    <div
        {{ $attributes->class([
            'mt-4 rounded-3xl border px-4 py-3 text-sm',
            'border-emerald-200 bg-emerald-50 text-emerald-900' => $level === 'success',
            'border-amber-200 bg-amber-50 text-amber-950' => $level === 'partial',
            'border-rose-200 bg-rose-50 text-rose-900' => in_array($level, ['error', 'failed'], true),
            'border-slate-200 bg-slate-50 text-slate-800' => ! in_array($level, ['success', 'partial', 'error', 'failed'], true),
            $class,
        ]) }}
    >
        {{ $message }}
    </div>
@endif
