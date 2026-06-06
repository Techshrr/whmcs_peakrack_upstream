[CmdletBinding()]
param(
    [string]$WhmcsRoot = $env:PEAKRACK_WHMCS_ROOT
)

$ErrorActionPreference = 'Stop'
$RepoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))

function Resolve-WhmcsRoot {
    param([string]$ConfiguredRoot)

    if (-not [string]::IsNullOrWhiteSpace($ConfiguredRoot)) {
        $Resolved = [System.IO.Path]::GetFullPath($ConfiguredRoot)
        if (
            (Test-Path -LiteralPath (Join-Path $Resolved 'init.php') -PathType Leaf) -and
            (Test-Path -LiteralPath (Join-Path $Resolved 'modules/addons') -PathType Container) -and
            (Test-Path -LiteralPath (Join-Path $Resolved 'modules/servers') -PathType Container)
        ) {
            return $Resolved
        }
        throw 'The configured WHMCS root does not contain init.php and both module directories.'
    }

    $Candidate = [System.IO.DirectoryInfo]$RepoRoot
    while ($null -ne $Candidate) {
        if (
            (Test-Path -LiteralPath (Join-Path $Candidate.FullName 'init.php') -PathType Leaf) -and
            (Test-Path -LiteralPath (Join-Path $Candidate.FullName 'modules/addons') -PathType Container) -and
            (Test-Path -LiteralPath (Join-Path $Candidate.FullName 'modules/servers') -PathType Container)
        ) {
            return $Candidate.FullName
        }
        $Candidate = $Candidate.Parent
    }

    throw 'Unable to locate the shared WHMCS root. Set PEAKRACK_WHMCS_ROOT or pass -WhmcsRoot.'
}

function Assert-SafeTarget {
    param(
        [Parameter(Mandatory = $true)][string]$Target,
        [Parameter(Mandatory = $true)][string]$ExpectedParent,
        [Parameter(Mandatory = $true)][string]$ExpectedLeaf
    )

    $TargetFull = [System.IO.Path]::GetFullPath($Target)
    $ParentFull = [System.IO.Path]::GetFullPath($ExpectedParent)
    $ParentPrefix = $ParentFull.TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
    if (
        -not $TargetFull.StartsWith($ParentPrefix, [System.StringComparison]::OrdinalIgnoreCase) -or
        [System.IO.Path]::GetFileName($TargetFull) -ne $ExpectedLeaf
    ) {
        throw "Unsafe installed module target: $TargetFull"
    }

    return $TargetFull
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

function Sync-Module {
    param(
        [Parameter(Mandatory = $true)][string]$Source,
        [Parameter(Mandatory = $true)][string]$DestinationParent,
        [Parameter(Mandatory = $true)][string]$ModuleName
    )

    $SourceFull = [System.IO.Path]::GetFullPath($Source)
    $Destination = Assert-SafeTarget -Target (Join-Path $DestinationParent $ModuleName) -ExpectedParent $DestinationParent -ExpectedLeaf $ModuleName
    if ($SourceFull -eq $Destination) {
        throw "Source and installed target are the same directory: $SourceFull"
    }
    if (-not (Test-Path -LiteralPath $SourceFull -PathType Container)) {
        throw "Source module directory is missing: $SourceFull"
    }

    $RepoRelativeSource = Get-RelativePath -BasePath $RepoRoot -Path $SourceFull
    $UntrackedSourceFiles = @(
        & git -C $RepoRoot ls-files --others --exclude-standard -- $RepoRelativeSource
    )
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to list untracked source files for $ModuleName."
    }
    $IgnoredSourceFiles = @(
        & git -C $RepoRoot ls-files --others --ignored --exclude-standard -- $RepoRelativeSource
    )
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to list ignored source files for $ModuleName."
    }
    $UnsafeSourceFiles = @()
    $UnsafeSourceFiles += $UntrackedSourceFiles
    $UnsafeSourceFiles += $IgnoredSourceFiles
    $UnsafeSourceFiles = @($UnsafeSourceFiles | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    if ($UnsafeSourceFiles.Count -gt 0) {
        throw "Untracked or ignored production module file cannot be synchronized: $($UnsafeSourceFiles[0])"
    }

    $TrackedSourceFiles = @(& git -C $RepoRoot ls-files -- $RepoRelativeSource)
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to list tracked source files for $ModuleName."
    }
    $SourcePrefix = $RepoRelativeSource.TrimEnd('/') + '/'
    if (-not (Test-Path -LiteralPath $Destination -PathType Container)) {
        New-Item -ItemType Directory -Path $Destination | Out-Null
    }

    $SourceFiles = @{}
    foreach ($RepoRelative in $TrackedSourceFiles) {
        $Normalized = $RepoRelative.Replace('\', '/')
        if (-not $Normalized.StartsWith($SourcePrefix, [System.StringComparison]::Ordinal)) {
            continue
        }
        $Relative = $Normalized.Substring($SourcePrefix.Length)
        $SourceFile = Join-Path $RepoRoot $RepoRelative
        if (-not (Test-Path -LiteralPath $SourceFile -PathType Leaf)) {
            throw "Tracked source module file is missing: $Normalized"
        }
        $SourceFiles[$Relative] = $SourceFile
    }
    if ($SourceFiles.Count -eq 0) {
        throw "No tracked source files found for $ModuleName."
    }

    foreach ($InstalledFile in Get-ChildItem -LiteralPath $Destination -Recurse -File) {
        $Relative = Get-RelativePath -BasePath $Destination -Path $InstalledFile.FullName
        if (-not $SourceFiles.ContainsKey($Relative)) {
            Remove-Item -LiteralPath $InstalledFile.FullName -Force
        }
    }

    foreach ($Relative in $SourceFiles.Keys) {
        $InstalledFile = Join-Path $Destination $Relative
        $InstalledDirectory = Split-Path -Parent $InstalledFile
        if (-not (Test-Path -LiteralPath $InstalledDirectory -PathType Container)) {
            New-Item -ItemType Directory -Path $InstalledDirectory -Force | Out-Null
        }
        Copy-Item -LiteralPath $SourceFiles[$Relative] -Destination $InstalledFile -Force
    }

    $InstalledFiles = @{}
    foreach ($File in Get-ChildItem -LiteralPath $Destination -Recurse -File) {
        $Relative = Get-RelativePath -BasePath $Destination -Path $File.FullName
        $InstalledFiles[$Relative] = $File.FullName
    }
    if ($SourceFiles.Count -ne $InstalledFiles.Count) {
        throw "Installed file count does not match source for $ModuleName."
    }
    foreach ($Relative in $SourceFiles.Keys) {
        if (-not $InstalledFiles.ContainsKey($Relative)) {
            throw "Installed file is missing for ${ModuleName}: $Relative"
        }
        $SourceHash = (Get-FileHash -LiteralPath $SourceFiles[$Relative] -Algorithm SHA256).Hash
        $InstalledHash = (Get-FileHash -LiteralPath $InstalledFiles[$Relative] -Algorithm SHA256).Hash
        if ($SourceHash -ne $InstalledHash) {
            throw "Installed file hash does not match source for ${ModuleName}: $Relative"
        }
    }

    Write-Host "Synchronized $ModuleName to $Destination"
}

$ResolvedWhmcsRoot = Resolve-WhmcsRoot -ConfiguredRoot $WhmcsRoot
Sync-Module `
    -Source (Join-Path $RepoRoot 'modules/addons/peakrack_upstream_api') `
    -DestinationParent (Join-Path $ResolvedWhmcsRoot 'modules/addons') `
    -ModuleName 'peakrack_upstream_api'
Sync-Module `
    -Source (Join-Path $RepoRoot 'modules/servers/peakrackupstream') `
    -DestinationParent (Join-Path $ResolvedWhmcsRoot 'modules/servers') `
    -ModuleName 'peakrackupstream'

Write-Host "Source and installed module hashes match under $ResolvedWhmcsRoot"
