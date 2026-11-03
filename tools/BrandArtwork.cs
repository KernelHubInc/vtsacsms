using System;
using System.Drawing;
using System.Drawing.Imaging;

public static class BrandArtwork
{
    public static Bitmap Transparent(Bitmap source, int padding)
    {
        var output = new Bitmap(source.Width + padding * 2, source.Height + padding * 2, PixelFormat.Format32bppArgb);
        for (var y = 0; y < source.Height; y++)
        {
            for (var x = 0; x < source.Width; x++)
            {
                var pixel = source.GetPixel(x, y);
                var minimum = Math.Min(pixel.R, Math.Min(pixel.G, pixel.B));
                var alpha = Math.Min(1.0, Math.Max(0.0, (248.0 - minimum) / 48.0));
                if (alpha < 0.16) continue;

                // Unmix the original white matte so antialiased edges also work on navy.
                var red = Unmatte(pixel.R, alpha);
                var green = Unmatte(pixel.G, alpha);
                var blue = Unmatte(pixel.B, alpha);
                output.SetPixel(x + padding, y + padding, Color.FromArgb((int)Math.Round(alpha * 255), red, green, blue));
            }
        }
        FillSmallHighlights(source, output, padding);
        return output;
    }

    private static void FillSmallHighlights(Bitmap source, Bitmap output, int padding)
    {
        var width = output.Width;
        var height = output.Height;
        var visited = new bool[width * height];
        var queue = new int[width * height];
        var dx = new[] { -1, 1, 0, 0 };
        var dy = new[] { 0, 0, -1, 1 };
        for (var index = 0; index < visited.Length; index++)
        {
            if (visited[index] || output.GetPixel(index % width, index / width).A != 0) continue;
            var count = 1;
            queue[0] = index;
            visited[index] = true;
            for (var head = 0; head < count; head++)
            {
                var x = queue[head] % width;
                var y = queue[head] / width;
                for (var direction = 0; direction < 4; direction++)
                {
                    var nextX = x + dx[direction];
                    var nextY = y + dy[direction];
                    if (nextX < 0 || nextY < 0 || nextX >= width || nextY >= height) continue;
                    var next = nextY * width + nextX;
                    if (visited[next] || output.GetPixel(nextX, nextY).A != 0) continue;
                    visited[next] = true;
                    queue[count++] = next;
                }
            }
            // Tiny enclosed white glints belong to the glossy artwork, not the background.
            if (count > 24) continue;
            for (var point = 0; point < count; point++)
            {
                var x = queue[point] % width;
                var y = queue[point] / width;
                if (x < padding || y < padding || x >= padding + source.Width || y >= padding + source.Height) continue;
                var pixel = source.GetPixel(x - padding, y - padding);
                output.SetPixel(x, y, Color.FromArgb(255, pixel.R, pixel.G, pixel.B));
            }
        }
    }

    public static Bitmap Monochrome(Bitmap source, Color ink)
    {
        var output = new Bitmap(source.Width, source.Height, PixelFormat.Format32bppArgb);
        for (var y = 0; y < source.Height; y++)
            for (var x = 0; x < source.Width; x++)
                output.SetPixel(x, y, Color.FromArgb(source.GetPixel(x, y).A, ink.R, ink.G, ink.B));
        return output;
    }

    private static int Unmatte(int value, double alpha)
    {
        return (int)Math.Round(Math.Min(255.0, Math.Max(0.0, (value - 255.0 * (1.0 - alpha)) / alpha)));
    }
}
