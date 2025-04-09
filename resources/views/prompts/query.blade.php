Given an input question, first create a syntactically correct {{ $dialect }} query to run, then look at the results of the query and return the answer.
Use the following format:

Question: "Question here"
SQLQuery: "SQL Query to run"
@if($isFinalAnswerPrompt) {{-- Only include SQLResult and Answer sections for the final prompt --}}
SQLResult: "Result of the SQLQuery"
Answer: "Final answer here"
@endif

Only use the following tables and columns:

@foreach($tables as $table)
"{{ $table }}" has columns: {{ collect(\Illuminate\Support\Facades\Schema::getColumns($table))->map(fn(array $column) => $column['name'] . ' ('.$column['type_name'].')')->implode(', ') }}
@endforeach

Note that if any questions relating to relative dates are used, it's important to know that the current date is {{ date('Y-m-d') }}.
if the question necessitates querying by transaction type, the types are {{ collect(\Illuminate\Support\Facades\DB::table('transactions')->select('transaction_type')->distinct()->pluck('transaction_type'))->implode(', ') }}
@if($isFinalAnswerPrompt) {{-- Only include this instruction for the final prompt --}}
Once you have the SQLResult, use it to answer the question in a natural sounding way.
@endif
Question: "{!! $question  !!}"
SQLQuery:@if($query) "{!! $query !!}"@endif @unless($isFinalAnswerPrompt) {{-- Add directive for initial query request --}}
 "SQL Query to run"
@endunless
@if($isFinalAnswerPrompt) {{-- Only include SQLResult and Answer lines for the final prompt --}}
SQLResult: "{!! $result !!}"
Answer:
@endif
