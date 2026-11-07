#version 410 core
// Heat haze (shader plan 4.2, PHPolygon\Rendering\PostProcess\HeatHaze): a
// fullscreen pass of the Scene stage. Above each heat source the hot air rises in a
// tall soft zone in which the image wobbles - fine waves climbing with time. Without
// sources (u_heat_count = 0) the pass copies its source.
//
// Inputs (HeatHazeSystem, SetShaderParameter):
//   u_heat_count       number of sources (up to 4)
//   u_heat_sources[k]  xy = the source on screen (0..1, origin top left),
//                      z = its radius on screen (fraction of the height), w = strength
in vec2 v_uv;
uniform sampler2D u_source;
uniform vec2  u_resolution;
uniform float u_time;
uniform int   u_heat_count;
uniform vec4  u_heat_sources[4];
out vec4 frag_color;

void main() {
    if (u_heat_count <= 0) {
        frag_color = texture(u_source, v_uv);
        return;
    }
    float aspect = u_resolution.x / max(u_resolution.y, 1.0);
    vec2 offset = vec2(0.0);
    for (int k = 0; k < 4; k++) {
        if (k >= u_heat_count) break;
        vec4 s = u_heat_sources[k];
        if (s.z <= 0.0 || s.w <= 0.0) continue;
        // The zone: as wide as the source, three times as tall, rising above it
        // (screen y grows downwards).
        vec2 d = (v_uv - s.xy) / s.z;
        d.x *= aspect;
        float up = -d.y;
        float zone = (1.0 - smoothstep(0.6, 1.2, abs(d.x))) * smoothstep(-0.4, 0.3, up) * (1.0 - smoothstep(1.5, 3.2, up));
        if (zone <= 0.0) continue;
        float t = u_time * 6.0;
        float wave = sin(v_uv.y * 140.0 + t) * 0.6 + sin(v_uv.y * 230.0 - t * 1.7 + v_uv.x * 40.0) * 0.4;
        float sway = sin(v_uv.y * 60.0 + t * 0.7 + v_uv.x * 25.0);
        offset += vec2(wave, sway * 0.5) * zone * s.w * 0.0035;
    }
    frag_color = texture(u_source, clamp(v_uv + offset, vec2(0.001), vec2(0.999)));
}
