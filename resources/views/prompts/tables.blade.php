Given the below input question and list of potential tables, output a comma separated list of the table names that may be necessary to answer this question.

It's important for you to know that the Transactions, Trial Balance, and Accounts table are all related, and those will be the primary tables being queried against.

Question: {{ $question }}
Table Names: @foreach($tables as $table){{ $table }},@endforeach

Relevant Table Names:
