<div style="max-width:720px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;font:15px/1.6 Arial,sans-serif;color:#222">
    @include('programs.partials.brand_header', ['title' => count($programs) > 1 ? 'Your applications' : 'Your application'])
    <div style="padding:24px">
        <p>Hello {{ $name }},</p>
        @if (count($programs) > 1)
            <p>{{ $organization }} has added your applications to Tujitume:</p>
            <ul>
                @foreach ($programs as $program)
                    <li><strong>{{ $program['title'] }}</strong>@if (!empty($program['tracking_url'])) · <a href="{{ $program['tracking_url'] }}">Track this application</a>@endif</li>
                @endforeach
            </ul>
        @else
            <p>{{ $organization }} has added your application to <strong>{{ $programs[0]['title'] }}</strong> to Tujitume.</p>
        @endif
        <p>You can now follow it on your dashboard and see the result when the review is done.</p>

        @if (!empty($password))
            <p>We made an account for you. Sign in with:</p>
            <table role="presentation" style="border-collapse:collapse;margin:8px 0 16px">
                <tr><td style="padding:4px 16px 4px 0;color:#555">Email</td><td style="padding:4px 0"><strong>{{ $email }}</strong></td></tr>
                <tr><td style="padding:4px 16px 4px 0;color:#555">Password</td><td style="padding:4px 0"><strong style="font-family:monospace;font-size:16px">{{ $password }}</strong></td></tr>
            </table>
            <p>You can change this password any time in your account settings after you sign in.</p>
            @include('programs.partials.brand_button', ['label' => 'Sign in', 'url' => $login_url])
        @elseif (!empty($has_account))
            <p>Sign in with your Tujitume account ({{ $email }}) and you will find it under My Applications.</p>
            @include('programs.partials.brand_button', ['label' => 'Sign in', 'url' => $login_url])
        @else
            @if (count($programs) === 1 && !empty($programs[0]['tracking_url']))
                @include('programs.partials.brand_button', ['label' => 'Track Your Application', 'url' => $programs[0]['tracking_url']])
            @endif
        @endif
        <p style="color:#666;font-size:13px">If you did not apply, you can ignore this email.</p>
        @include('programs.partials.brand_footer')
    </div>
</div>
