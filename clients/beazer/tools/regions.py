"""UI regions of the approved 1536x1024 mockup that are rebuilt as live HTML.

Coordinates are (x0, y0, x1, y1) in mockup pixels. These are inpainted out of the
artwork so the real, clickable elements can sit on top of a clean scene.
"""

NAV = [
    (88, 2, 296, 66),        # red brand mark
    (404, 14, 1122, 56),     # nav labels + HOME underline
    (1364, 10, 1470, 54),    # search + cart icons
    (0, 51, 1536, 64),       # thin separator line
]
TAGLINE = [(622, 336, 1206, 369)]
BUTTONS = [(604, 384, 874, 461), (882, 388, 1170, 459)]
ICONS = [  # glyph boxes, then label boxes (tight, so the crowd gets rebuilt between them)
    (409, 494, 461, 546), (554, 496, 595, 543), (689, 499, 752, 544),
    (838, 494, 890, 546), (988, 496, 1031, 545), (1132, 495, 1183, 546),
    (404, 547, 466, 569), (547, 547, 603, 569), (684, 547, 754, 569),
    (823, 547, 906, 569), (983, 547, 1037, 569), (1117, 547, 1198, 569),
]
RELEASE = [(96, 582, 764, 842)]
VIDEO = [(804, 586, 1424, 844)]
UI = NAV + TAGLINE + BUTTONS + ICONS + RELEASE + VIDEO

# Hero wordmark (kept in the desktop art, removed for the stacked mobile art).
WORDMARK = [(536, 112, 1290, 338)]

# Source crops for content images (inside their white frames).
COVER = (128, 623, 355, 832)
VIDEO_THUMB = (816, 625, 1411, 831)
PLAY_BUTTON = (1107, 731, 47)  # centre x, centre y, radius
