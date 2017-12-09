@component('mail::message')
# Hi,

{{ $user }} has applied for **{{ $level }}** in the skill **{{ $skill }}**, with the reasoning:

@component('mail::panel')
{{ $reasoning }}
@endcomponent

@component('mail::button', ['url' => $url])
View Proposal
@endcomponent

If you require more information about the proposal, you can contact the member by replying to this email.
@endcomponent