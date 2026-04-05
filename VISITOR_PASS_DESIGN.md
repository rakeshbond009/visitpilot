# Visitor Pass PDF Design System (v1.0.6)

This document serves as the technical reference for the high-fidelity PDF Visitor Pass generation system implemented for VisitPilot. Use this as a reference if the layout needs adjustment or if the system is migrated to a new server.

## 1. Core Architecture
The system uses a modified version of **FPDF** (FPDF 1.86). Standard FPDF was insufficient for this project's premium design requirements.

### Essential Class Extensions (in `includes/fpdf.php`)
We have extended the base FPDF class with three critical methods to support modern aesthetics:
- **`RoundedRect($x, $y, $w, $h, $r, $style, $corners)`**: Used for the card body and the detail grid boxes.
- **`ClippingRoundedRect($x, $y, $w, $h, $r)`**: Essential for the photo area. It creates a vector mask that "cuts" the rectangular image into a rounded-corner shape.
- **`UnsetClipping()`**: Must be called immediately after the `Image()` call to return the graphics state to normal.

## 2. Design Tokens & Geometry
- **Primary Brand Blue**: `rgb(17, 97, 238)` (#1161EE).
- **Background Grey**: `rgb(244, 247, 246)` (Canvas backdrop).
- **Detail Box Grey**: `rgb(242, 243, 245)` (#F2F3F5).
- **Font Face**: Helvetica / Arial (Standard FPDF cores).

### Coordinate Map (100mm x 210mm Canvas)
| Component | X Coord | Y Coord | Width | Height | Radius |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Outer Card** | 10 | 15 | 80mm | 185mm | 8mm |
| **Top Banner** | 10 | 15 | 80mm | 48mm | 8mm |
| **Photo Frame (White)**| 26 | 57 | 48mm | 48mm | 8mm |
| **Visible Photo** | 28 | 59 | 44mm | 44mm | 8mm |
| **Details Container** | 15 | 132 | 70mm | 32mm | 4mm |

## 3. Implementation Logic
### The "Broad Border" System
To achieve the professional white border look from the digital pass:
1. Draw a white `RoundedRect` of 48x48mm.
2. Activate the `ClippingRoundedRect` at a 2mm offset (28x59mm) with a size of 44x44mm.
3. Place the Image at those same 28x59mm coordinates.
4. The result is a 44mm rounded image perfectly centered inside a 48mm rounded white frame.

### Typographic Scaling
- **VISITOR PASS Header**: 24pt Bold.
- **Visitor Name**: 16pt Bold (UPPERCASE). This size was selected to prevent overflow on long names while remaining high-impact.
- **Visit Code**: 12pt Bold (Blue).

## 4. Troubleshooting & Server Requirements
- **PHP extension**: Requires `GD` for image processing if images are not standard formats.
- **Permissions**: `uploads/passes/` and `uploads/visitors/` must be writable. 
- **File Locks**: If you get a "FAILED generation" error on Windows, ensure the PDF file is not open in a browser tab, as Windows prevents writing to locked files.
- **Version Tracking**: The current production version is **v1.0.6**, visible in the footer of every generated pass.
