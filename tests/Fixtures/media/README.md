# Encoded container fixtures

The MP3 files contain 0.1 seconds of mono silence at 44,100, 22,050 and
11,025 Hz (MPEG 1, 2 and 2.5 respectively). `silence.ogg` contains 0.1
seconds of stereo Vorbis silence. Generate them from this directory with:

```sh
ffmpeg -nostdin -v error -f lavfi -i anullsrc=r=44100:cl=mono -t 0.1 -c:a libmp3lame -b:a 64k -fflags +bitexact -flags:a +bitexact -map_metadata -1 -id3v2_version 0 -write_xing 0 mpeg1.mp3
ffmpeg -nostdin -v error -f lavfi -i anullsrc=r=22050:cl=mono -t 0.1 -c:a libmp3lame -b:a 32k -fflags +bitexact -flags:a +bitexact -map_metadata -1 -id3v2_version 0 -write_xing 0 mpeg2.mp3
ffmpeg -nostdin -v error -f lavfi -i anullsrc=r=11025:cl=mono -t 0.1 -c:a libmp3lame -b:a 16k -fflags +bitexact -flags:a +bitexact -map_metadata -1 -id3v2_version 0 -write_xing 0 mpeg25.mp3
ffmpeg -nostdin -v error -f lavfi -i anullsrc=r=44100:cl=stereo -t 0.1 -c:a vorbis -strict -2 -fflags +bitexact -flags:a +bitexact -map_metadata -1 silence.ogg
```

`idat.avif` is a 1×1 black image encoded with PHP GD (`imageavif` quality 60,
speed 6). Its AV1 payload was moved from `mdat` into a direct `meta/idat`
child; `iloc` version 1 uses construction method 1 and extent offset zero.
The item length and image properties are preserved and box lengths rebuilt.
Both GD and `avifdec` decode it; the audio files decode with FFmpeg.

These fixtures test container framing separately from codec decoding. The
validator retains only one page or frame header, so multiplexed streams and
packets spanning Ogg pages must never require a growing serial-number map.
