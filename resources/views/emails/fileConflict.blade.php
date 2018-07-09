@extends('beautymail::templates.widgets')

@section('content')

    @include('beautymail::templates.widgets.articleStart')

    <h4 class="secondary"><strong>[{{ $file->app }}] New file conflict found</strong></h4>
    <p>{{ $file->file }}</p>

    @foreach($file->conflicts as $conflict)
        <code>{{ $conflict->toJson() }}</code>
        <br/>
    @endforeach

    @include('beautymail::templates.widgets.articleEnd')

@stop