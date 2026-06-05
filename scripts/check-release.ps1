[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$Root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$PackageRoot = [System.IO.Path]::GetFullPath((Join-Path $Root 'release-packages'))
$RootPrefix = $Root.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar

if (-not $PackageRoot.StartsWith($RootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'The release package path is outside the repository.'
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$Command,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Command failed with exit code $LASTEXITCODE."
    }
}

function Assert-RequiredFile {
    param([Parameter(Mandatory = $true)][string]$RelativePath)

    $Path = Join-Path $Root $RelativePath
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Required release file is missing: $RelativePath"
    }
}

function Get-RelativePath {
    param(
        [Parameter(Mandatory = $true)][string]$BasePath,
        [Parameter(Mandatory = $true)][string]$Path
    )

    $BaseFull = [System.IO.Path]::GetFullPath($BasePath).TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
    $PathFull = [System.IO.Path]::GetFullPath($Path)
    $Prefix = $BaseFull + [System.IO.Path]::DirectorySeparatorChar
    if (-not $PathFull.StartsWith($Prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Path is outside the expected base directory: $PathFull"
    }

    return $PathFull.Substring($Prefix.Length).Replace('\', '/')
}

Write-Host 'Running tests...'
Invoke-Checked -Command 'php' -Arguments @((Join-Path $Root 'tests/run.php'))

Write-Host 'Checking PowerShell script syntax...'
foreach ($Script in Get-ChildItem -LiteralPath (Join-Path $Root 'scripts') -File -Filter '*.ps1') {
    $Tokens = $null
    $ParseErrors = $null
    [System.Management.Automation.Language.Parser]::ParseFile(
        $Script.FullName,
        [ref]$Tokens,
        [ref]$ParseErrors
    ) | Out-Null
    if ($ParseErrors.Count -gt 0) {
        throw "PowerShell syntax failed: $($Script.Name): $($ParseErrors[0].Message)"
    }
}

Write-Host 'Linting PHP files...'
$PhpFiles = Get-ChildItem -LiteralPath $Root -Recurse -File -Filter '*.php' |
    Where-Object { $_.FullName -notlike "$PackageRoot*" }
foreach ($File in $PhpFiles) {
    & php -l $File.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "PHP lint failed: $(Get-RelativePath -BasePath $Root -Path $File.FullName)"
    }
}

Write-Host 'Checking release metadata and source headers...'
$RequiredFiles = @(
    'README.md',
    'README.zh-CN.md',
    'CHANGELOG.md',
    'UPGRADE.md',
    'UPGRADE.zh-CN.md',
    'SECURITY.md',
    'LICENSE',
    'NOTICE',
    'VERSION',
    'docs/api-v1.md',
    'modules/addons/peakrack_upstream_api/LICENSE',
    'modules/addons/peakrack_upstream_api/NOTICE',
    'modules/servers/peakrackupstream/LICENSE',
    'modules/servers/peakrackupstream/NOTICE'
)
foreach ($RequiredFile in $RequiredFiles) {
    Assert-RequiredFile -RelativePath $RequiredFile
}

$ModuleRoots = @(
    (Join-Path $Root 'modules/addons/peakrack_upstream_api'),
    (Join-Path $Root 'modules/servers/peakrackupstream')
)
foreach ($ModuleRoot in $ModuleRoots) {
    foreach ($File in Get-ChildItem -LiteralPath $ModuleRoot -Recurse -File -Filter '*.php') {
        $Relative = Get-RelativePath -BasePath $ModuleRoot -Path $File.FullName
        if ($Relative -like 'lang/*') {
            continue
        }
        $Contents = Get-Content -LiteralPath $File.FullName -Raw
        if (
            -not $Contents.Contains('SPDX-License-Identifier: Apache-2.0') -or
            -not $Contents.Contains('https://github.com/Techshrr/whmcs_peakrack_upstream') -or
            -not $Contents.Contains('Copyright 2026 PeakRack.')
        ) {
            throw "Required source header is missing: $Relative"
        }
    }
}

Write-Host 'Scanning tracked files for credential patterns...'
$TrackedFiles = & git -C $Root ls-files
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to list tracked files for credential scanning.'
}
$UntrackedModuleFiles = @(
    & git -C $Root ls-files --others --exclude-standard -- 'modules/addons/peakrack_upstream_api' 'modules/servers/peakrackupstream'
)
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to list untracked production module files.'
}
$UntrackedModuleFiles = @($UntrackedModuleFiles | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
if ($UntrackedModuleFiles.Count -gt 0) {
    throw "Untracked production module file cannot be packaged: $($UntrackedModuleFiles[0])"
}
$ForbiddenTrackedNames = @('.env', 'configuration.php')
$CredentialPatterns = @(
    'AKIA[0-9A-Z]{16}',
    'gh[pousr]_[A-Za-z0-9]{36,}',
    'sk_live_[A-Za-z0-9]{16,}',
    '-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
    '(?i)(?:api[_-]?secret|secret[_-]?key|password)\s*[:=]\s*[''"][A-Za-z0-9+/=_-]{24,}[''"]',
    'pr[ks]_[0-9a-f]{40,64}'
)
foreach ($Relative in $TrackedFiles) {
    if ([string]::IsNullOrWhiteSpace($Relative)) {
        continue
    }
    $Normalized = $Relative.Replace('\', '/')
    if ($ForbiddenTrackedNames -contains [System.IO.Path]::GetFileName($Normalized)) {
        throw "Sensitive runtime file is tracked: $Normalized"
    }
    $Path = Join-Path $Root $Relative
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        continue
    }
    $Contents = Get-Content -LiteralPath $Path -Raw -ErrorAction SilentlyContinue
    if ($null -eq $Contents) {
        continue
    }
    foreach ($Pattern in $CredentialPatterns) {
        if ($Contents -match $Pattern) {
            throw "Possible credential pattern found in tracked file: $Normalized"
        }
    }
}

Write-Host 'Building production module packages...'
if (Test-Path -LiteralPath $PackageRoot) {
    Remove-Item -LiteralPath $PackageRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $PackageRoot | Out-Null

$Version = (Get-Content -LiteralPath (Join-Path $Root 'VERSION') -Raw).Trim()
if ($Version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+$') {
    throw 'VERSION must contain a semantic version.'
}

$Packages = @(
    @{
        Name = 'peakrack-upstream-api'
        Source = 'modules/addons/peakrack_upstream_api'
        Folder = 'peakrack_upstream_api'
    },
    @{
        Name = 'peakrackupstream'
        Source = 'modules/servers/peakrackupstream'
        Folder = 'peakrackupstream'
    }
)

foreach ($Package in $Packages) {
    $Stage = Join-Path $PackageRoot ('stage-' + $Package.Name)
    $StageModule = Join-Path $Stage $Package.Folder
    New-Item -ItemType Directory -Path $StageModule | Out-Null

    $SourcePrefix = $Package.Source.TrimEnd('/') + '/'
    $TrackedPackageFiles = @(
        $TrackedFiles | Where-Object {
            $_.Replace('\', '/').StartsWith($SourcePrefix, [System.StringComparison]::Ordinal)
        }
    )
    if ($TrackedPackageFiles.Count -eq 0) {
        throw "No tracked production files found for package $($Package.Name)."
    }
    foreach ($RelativePath in $TrackedPackageFiles) {
        $Normalized = $RelativePath.Replace('\', '/')
        $InsidePackage = $Normalized.Substring($SourcePrefix.Length)
        $DestinationFile = Join-Path $StageModule $InsidePackage
        $DestinationDirectory = Split-Path -Parent $DestinationFile
        if (-not (Test-Path -LiteralPath $DestinationDirectory -PathType Container)) {
            New-Item -ItemType Directory -Path $DestinationDirectory -Force | Out-Null
        }
        Copy-Item -LiteralPath (Join-Path $Root $RelativePath) -Destination $DestinationFile -Force
    }

    $PackageFiles = Get-ChildItem -LiteralPath $StageModule -Recurse -File
    if ($PackageFiles.Count -eq 0) {
        throw "The staged production package is empty: $($Package.Name)"
    }

    # Production packages must never include tests/fixtures or any test tree.
    $FixtureFiles = $PackageFiles |
        Where-Object { (Get-RelativePath -BasePath $Stage -Path $_.FullName) -match '(^|/)tests/fixtures(/|$)' }
    if ($FixtureFiles.Count -gt 0) {
        throw "Test fixtures were copied into package $($Package.Name)."
    }

    $Zip = Join-Path $PackageRoot ($Package.Name + '-v' + $Version + '.zip')
    Compress-Archive -LiteralPath $StageModule -DestinationPath $Zip -CompressionLevel Optimal
    $Hash = (Get-FileHash -LiteralPath $Zip -Algorithm SHA256).Hash.ToLowerInvariant()
    $Checksum = "$Hash  $([System.IO.Path]::GetFileName($Zip))"
    Set-Content -LiteralPath ($Zip + '.sha256') -Value $Checksum -Encoding ascii
    Remove-Item -LiteralPath $Stage -Recurse -Force
}

Write-Host "Release checks completed. Packages: $PackageRoot"
