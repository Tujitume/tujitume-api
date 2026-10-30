<p>Hello {{ $recipientName ?? 'Reviewer' }},</p>
@if($accepted ?? false)<p>The organization accepted your proposed fee. You can now accept the visit.</p>@else<p>The organization declined your proposed fee. You can accept the visit at the original fee, or decline it.</p>@endif
