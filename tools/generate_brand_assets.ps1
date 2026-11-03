$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$repo = Split-Path $PSScriptRoot -Parent
$branding = Join-Path $repo 'apps/platform/public/branding'
$artwork = [System.Drawing.Bitmap]::new((Join-Path $branding 'power-solutions-logo-artwork.png'))
$padding = 64
$master = [System.Drawing.Bitmap]::new(($artwork.Width + 2*$padding), ($artwork.Height + 2*$padding))
$logoCanvas = [System.Drawing.Graphics]::FromImage($master)
$logoCanvas.Clear([System.Drawing.Color]::White)
$logoCanvas.DrawImageUnscaled($artwork, $padding, $padding)
$logoCanvas.Dispose()
$master.Save((Join-Path $branding 'power-solutions-logo.png'), [System.Drawing.Imaging.ImageFormat]::Png)

function Save-BrandImage($image, [string]$path, [int]$width, [int]$height) {
    $target = [System.Drawing.Bitmap]::new($width, $height)
    $canvas = [System.Drawing.Graphics]::FromImage($target)
    $canvas.Clear([System.Drawing.Color]::White)
    $canvas.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $canvas.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $scale = [Math]::Min($width / $image.Width, $height / $image.Height)
    $w = [int][Math]::Round($image.Width * $scale)
    $h = [int][Math]::Round($image.Height * $scale)
    $canvas.DrawImage($image, [int](($width-$w)/2), [int](($height-$h)/2), $w, $h)
    $canvas.Dispose()
    [System.IO.Directory]::CreateDirectory((Split-Path $path -Parent)) | Out-Null
    $target.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    $target.Dispose()
}

# Compact icons use the supplied tile motif; full lockups retain the company line.
$motif = $artwork.Clone([System.Drawing.Rectangle]::new(0, 0, [int]($artwork.Width * .215), [int]($artwork.Height * .9)), $artwork.PixelFormat)
$icon = [System.Drawing.Bitmap]::new(1024, 1024)
$canvas = [System.Drawing.Graphics]::FromImage($icon)
$canvas.Clear([System.Drawing.Color]::White)
$canvas.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$scale = 710 / [Math]::Max($motif.Width, $motif.Height)
$w = [int]($motif.Width * $scale); $h = [int]($motif.Height * $scale)
$canvas.DrawImage($motif, [int]((1024-$w)/2), [int]((1024-$h)/2), $w, $h)
$canvas.Dispose()

foreach ($name in @('power-solutions-logo-horizontal.png', 'power-solutions-logo-horizontal-dark.png')) {
    Copy-Item -LiteralPath (Join-Path $branding 'power-solutions-logo.png') -Destination (Join-Path $branding $name) -Force
    Copy-Item -LiteralPath (Join-Path $branding 'power-solutions-logo.png') -Destination (Join-Path $repo "apps/mobile/assets/branding/$name") -Force
}
Save-BrandImage $master (Join-Path $branding 'power-solutions-email-logo.png') 1200 ([int][Math]::Round(1200*$master.Height/$master.Width))
Save-BrandImage $icon (Join-Path $branding 'power-solutions-mark.png') 512 512
Save-BrandImage $icon (Join-Path $branding 'power-solutions-favicon.png') 64 64
$png = [System.IO.File]::ReadAllBytes((Join-Path $branding 'power-solutions-favicon.png'))
$stream = [System.IO.File]::Create((Join-Path $repo 'apps/platform/public/favicon.ico'))
$writer = [System.IO.BinaryWriter]::new($stream)
$writer.Write([uint16]0); $writer.Write([uint16]1); $writer.Write([uint16]1)
$writer.Write([byte]64); $writer.Write([byte]64); $writer.Write([byte]0); $writer.Write([byte]0)
$writer.Write([uint16]1); $writer.Write([uint16]32); $writer.Write([uint32]$png.Length); $writer.Write([uint32]22)
$writer.Write($png); $writer.Dispose()
Save-BrandImage $icon (Join-Path $branding 'power-solutions-apple-touch-icon.png') 180 180
Save-BrandImage $icon (Join-Path $repo 'apps/mobile/assets/branding/power-solutions-mark.png') 512 512
Save-BrandImage $icon (Join-Path $repo 'apps/mobile/web/favicon.png') 64 64
foreach ($size in @(192, 512)) {
    foreach ($name in @("Icon-$size.png", "Icon-maskable-$size.png")) {
        Save-BrandImage $icon (Join-Path $repo "apps/mobile/web/icons/$name") $size $size
    }
}
$android = Join-Path $repo 'apps/mobile/android/app/src/main/res'
foreach ($density in @{mdpi=48; hdpi=72; xhdpi=96; xxhdpi=144; xxxhdpi=192}.GetEnumerator()) {
    Save-BrandImage $icon (Join-Path $android "mipmap-$($density.Key)/ic_launcher.png") $density.Value $density.Value
}
Save-BrandImage $icon (Join-Path $android 'drawable-nodpi/power_solutions_icon_foreground.png') 432 432
$ios = Join-Path $repo 'apps/mobile/ios/Runner/Assets.xcassets'
foreach ($folder in @('AppIcon.appiconset', 'LaunchImage.imageset')) {
    foreach ($path in Get-ChildItem -LiteralPath (Join-Path $ios $folder) -Filter '*.png') {
        $current = [System.Drawing.Bitmap]::new($path.FullName)
        $width=$current.Width; $height=$current.Height; $current.Dispose()
        Save-BrandImage $icon $path.FullName $width $height
    }
}
# Historical URLs also display the new artwork for cached clients.
$data=[Convert]::ToBase64String([System.IO.File]::ReadAllBytes((Join-Path $branding 'power-solutions-logo.png')))
foreach ($name in @('horizontal', 'horizontal-dark', 'stacked', 'monochrome')) {
    $svg='<svg xmlns="http://www.w3.org/2000/svg" width="'+$master.Width+'" height="'+$master.Height+'" viewBox="0 0 '+$master.Width+' '+$master.Height+'"><image width="100%" height="100%" href="data:image/png;base64,'+$data+'"/></svg>'
    [System.IO.File]::WriteAllText((Join-Path $branding "power-solutions-logo-$name.svg"), $svg)
}
[System.IO.File]::WriteAllText((Join-Path $branding 'power-solutions-social-card.svg'), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630"><rect width="1200" height="630" fill="white"/><image x="80" y="130" width="1040" height="370" href="data:image/png;base64,'+$data+'"/></svg>')
$data=[Convert]::ToBase64String([System.IO.File]::ReadAllBytes((Join-Path $branding 'power-solutions-mark.png')))
[System.IO.File]::WriteAllText((Join-Path $branding 'power-solutions-mark.svg'), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><image width="512" height="512" href="data:image/png;base64,'+$data+'"/></svg>')
$icon.Dispose(); $motif.Dispose(); $master.Dispose(); $artwork.Dispose()
Write-Output 'BRAND_ASSETS_EXPORTED'
