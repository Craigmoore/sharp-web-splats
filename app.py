import os
import torch
import torch.nn.functional as F
import numpy as np
from flask import Flask, request, render_template, jsonify, send_from_directory
from pathlib import Path
import logging

# Sharp imports
from sharp.models import PredictorParams, RGBGaussianPredictor, create_predictor
from sharp.utils import io
from sharp.utils import logging as logging_utils
from sharp.utils.gaussians import (
    Gaussians3D,
    save_ply,
    unproject_gaussians,
)

app = Flask(__name__)
logging.basicConfig(level=logging.INFO)
LOGGER = logging.getLogger("app")

@app.route('/node_modules/<path:filename>')
def node_modules(filename):
    return send_from_directory(os.path.join(app.root_path, 'node_modules'), filename)

# Configuration
DEFAULT_MODEL_URL = "https://ml-site.cdn-apple.com/models/sharp/sharp_2572gikvuh.pt"

# Environment variables
HOST = os.getenv('HOST', '0.0.0.0')
PORT = int(os.getenv('PORT', '8080'))
USE_SSL = os.getenv('USE_SSL', 'true').lower() in ('true', '1', 'yes')
FORCE_CPU = os.getenv('FORCE_CPU', 'false').lower() in ('true', '1', 'yes')

# Device selection
if FORCE_CPU:
    device = "cpu"
    LOGGER.info("Forced CPU mode via FORCE_CPU environment variable")
else:
    device = "cuda" if torch.cuda.is_available() else ("mps" if torch.mps.is_available() else "cpu")
LOGGER.info(f"Using device: {device}")

# Global model
gaussian_predictor = None

def load_model():
    global gaussian_predictor
    LOGGER.info("Loading model...")
    state_dict = torch.hub.load_state_dict_from_url(DEFAULT_MODEL_URL, progress=True)
    gaussian_predictor = create_predictor(PredictorParams())
    gaussian_predictor.load_state_dict(state_dict)
    gaussian_predictor.eval()
    gaussian_predictor.to(device)
    LOGGER.info("Model loaded.")

# Inference Logic (adapted from sharp/cli/predict.py)
@torch.no_grad()
def predict_image(image: np.ndarray, f_px: float) -> Gaussians3D:
    internal_shape = (1536, 1536)
    
    # Preprocessing
    image_pt = torch.from_numpy(image.copy()).float().to(device).permute(2, 0, 1) / 255.0
    _, height, width = image_pt.shape
    disparity_factor = torch.tensor([f_px / width]).float().to(device)

    image_resized_pt = F.interpolate(
        image_pt[None],
        size=(internal_shape[1], internal_shape[0]),
        mode="bilinear",
        align_corners=True,
    )

    # Inference
    gaussians_ndc = gaussian_predictor(image_resized_pt, disparity_factor)

    # Postprocessing
    intrinsics = (
        torch.tensor(
            [
                [f_px, 0, width / 2, 0],
                [0, f_px, height / 2, 0],
                [0, 0, 1, 0],
                [0, 0, 0, 1],
            ]
        )
        .float()
        .to(device)
    )
    intrinsics_resized = intrinsics.clone()
    intrinsics_resized[0] *= internal_shape[0] / width
    intrinsics_resized[1] *= internal_shape[1] / height

    gaussians = unproject_gaussians(
        gaussians_ndc, torch.eye(4).to(device), intrinsics_resized, internal_shape
    )
    return gaussians

@app.route('/')
def index():
    return render_template('index.html')

@app.route('/generate', methods=['POST'])
def generate():
    if 'image' not in request.files:
        return jsonify({"error": "No image uploaded"}), 400
    
    file = request.files['image']
    if file.filename == '':
        return jsonify({"error": "No selected file"}), 400

    # Save input file
    input_path = Path("static/input.jpg") # Overwrite for simplicity or use unique name
    file.save(input_path)
    
    # Run Inference
    try:
        LOGGER.info(f"Processing {input_path}")
        image, _, f_px = io.load_rgb(input_path)
        height, width = image.shape[:2]
        
        gaussians = predict_image(image, f_px)
        
        output_filename = "output.ply"
        output_path = Path("static") / output_filename
        
        save_ply(gaussians, f_px, (height, width), output_path)
        
        # Compress the PLY file for better performance
        compressed_path = compress_ply(output_path)
        
        if compressed_path and compressed_path.exists():
            return jsonify({"url": f"/static/{compressed_path.name}?t={os.urandom(4).hex()}"})
        else:
            # Fall back to uncompressed if compression fails
            LOGGER.warning("Compression failed, returning uncompressed PLY")
            return jsonify({"url": f"/static/{output_filename}?t={os.urandom(4).hex()}"})
    except Exception as e:
        LOGGER.error(f"Error: {e}")
        return jsonify({"error": str(e)}), 500

def compress_ply(ply_path: Path) -> Path:
    """Compress a PLY file to .sog format using splat-transform"""
    import subprocess
    
    sog_path = ply_path.with_suffix('.sog')
    
    try:
        LOGGER.info(f"Compressing {ply_path.name} to .sog...")
        result = subprocess.run(
            ['splat-transform', str(ply_path), str(sog_path)],
            capture_output=True,
            text=True,
            check=True,
            timeout=30
        )
        
        if sog_path.exists():
            orig_size = ply_path.stat().st_size / 1024 / 1024
            comp_size = sog_path.stat().st_size / 1024 / 1024
            LOGGER.info(f"Compressed: {orig_size:.2f} MB → {comp_size:.2f} MB ({comp_size/orig_size*100:.1f}%)")
            return sog_path
        else:
            LOGGER.error("Compression failed: output file not created")
            return None
    except subprocess.TimeoutExpired:
        LOGGER.error("Compression timed out")
        return None
    except FileNotFoundError:
        LOGGER.error("splat-transform not found. Install with: npm install -g @playcanvas/splat-transform")
        return None
    except Exception as e:
        LOGGER.error(f"Compression error: {e}")
        return None

@app.route('/api/samples')
def get_samples():
    samples_dir = Path("static/samples")
    if not samples_dir.exists():
        return jsonify([])

    samples = []
    extensions = {".jpg", ".jpeg", ".png"}
    for file_path in samples_dir.iterdir():
        if file_path.suffix.lower() in extensions:
            # Prefer .sog (compressed) version if it exists, fallback to .ply
            sog_path = file_path.with_suffix('.sog')
            ply_path = file_path.with_suffix(".ply")

            if sog_path.exists():
                splat_file = sog_path
            elif ply_path.exists():
                splat_file = ply_path
            else:
                continue

            samples.append({
                "name": file_path.stem,
                "image": f"/static/samples/{file_path.name}",
                "splat": f"/static/samples/{splat_file.name}"
            })
    return jsonify(samples)

@app.route('/health')
def health():
    """Health check endpoint for Docker and orchestration"""
    status = {
        "status": "ok",
        "device": device,
        "model_loaded": gaussian_predictor is not None
    }
    return jsonify(status), 200

if __name__ == '__main__':
    load_model()
    # Run with SSL to enable Secure Context (required for WebXR on remote/local IP)
    # SSL can be disabled via USE_SSL=false for internal Docker networking
    ssl_context = 'adhoc' if USE_SSL else None
    LOGGER.info(f"Starting server on {HOST}:{PORT} (SSL: {USE_SSL})")
    app.run(host=HOST, port=PORT, ssl_context=ssl_context)
