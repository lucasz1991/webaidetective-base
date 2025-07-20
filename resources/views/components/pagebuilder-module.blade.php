<div  class="{{ $position }}" >
    @if($modules && count($modules) > 0)
        @foreach($modules as $module)
        <div  id="module-{{ $module->id }}" class="module-{{ $module->id }}">
            {!! $module->cleaned_html !!}
            <style>{!! $module->css !!}</style>
            
            <script>{!! $module->js !!}</script>
            
        </div>
        @endforeach
    @endif
</div>
