# Guest program applications

Visitors can submit the standard `POST /api/v1/programs/{program}/applications` form without signing in. The existing `program_applications` table stores their address in `email`, keeps `user_id` null, and runs the same round answers, document handling, scoring, and routing workflow as signed-in applications.

After submission, the applicant receives a confirmation with a sign-up link. Set `FRONTEND_URL` to the public frontend origin if it differs from the default. The confirmation is sent after the application transaction commits; mail delivery errors are logged and do not remove the application.

When a business-owner registration email has a completed record in `email_verification_codes`, unclaimed applications with that same email are assigned to the new account. Registration without a verified OTP does not claim applications. Linking changes only `user_id`, preserving application content, documents, state, and related history.
