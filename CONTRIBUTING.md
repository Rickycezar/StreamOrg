# Contributing

Thank you for wanting to improve StreamOrg! Bug reports, ideas and feedback
are welcome as GitHub issues.

## Before you send code: the Contributor License Agreement

StreamOrg is licensed to the public under the PolyForm Noncommercial License
1.0.0, and its author also offers it commercially (for example as a hosted
service). To keep that possible, every code contribution must come with the
agreement below. **By opening a pull request you confirm that you have read
and agree to it**, and you will be asked to confirm it explicitly on your
first pull request.

### Contributor License Agreement

1. **Your contribution.** "Contribution" means any code, documentation or
   other material you submit to this project, in a pull request or otherwise.
2. **You keep your copyright**, and you grant Henrique Barros (the "Owner") a
   perpetual, worldwide, non-exclusive, royalty-free, irrevocable license to
   use, reproduce, modify, distribute, sublicense and sell your Contribution,
   as part of StreamOrg or otherwise, **under any license terms the Owner
   chooses, including commercial and proprietary ones**.
3. **Patents.** You grant the Owner and recipients of StreamOrg a perpetual,
   worldwide, royalty-free, irrevocable license under any patent claims you
   can license that are necessarily infringed by your Contribution.
4. **It is yours to give.** You confirm that the Contribution is your original
   work (or that you have the right to submit it under these terms), and that
   it does not include anyone else's code or material without a license
   compatible with this agreement. If your employer has rights to what you
   create, you confirm that it has allowed you to make this Contribution.
5. **No obligation.** The Owner is not required to use your Contribution, and
   you provide it "as is", without warranty.

## How to contribute

1. Open an issue first for anything larger than a small fix, so we can agree
   on the approach.
2. Keep pull requests focused; follow the style of the surrounding code.
3. Run the checks before submitting:

   ```bash
   composer test
   php bin/check_lang.php
   ```

4. Add or update translations in both `lang/en.php` and `lang/pt-BR.php` for
   any text shown to users.
5. In your first pull request, include the line:

   > I have read and agree to the Contributor License Agreement in CONTRIBUTING.md.

Security problems: please do not open a public issue — write to
**streamorg@outlook.com** instead.
