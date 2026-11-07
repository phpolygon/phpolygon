#version 410 core
// Sun shafts in screen space (shader plan 4.2, PHPolygon\Rendering\PostProcess\SunShafts).
// A fullscreen pass of the Scene stage (linear scene colour): from every pixel it
// walks towards the sun's screen position and gathers what it passes of the open
// sky - the bright sky round the sun, cut by everything in front of it - so
// light streams out between buildings, trees and clouds' gaps. Without a sun on
// or near the screen (u_sun_shafts.w = 0) the pass copies its source.
//
// Inputs (SunShaftsSystem, SetShaderParameter):
//   u_sun_shafts       xy = sun on screen (0..1, origin top left), z = strength,
//                      w = 1 while the sun is up and in front
//   u_sun_shaft_color  rgb = linear colour of the light
in vec2 v_uv;
uniform sampler2D u_source;
uniform sampler2D u_scene_depth_raw;
uniform vec2  u_resolution;
uniform vec4  u_sun_shafts;
uniform vec4  u_sun_shaft_color;
out vec4 frag_color;

const int SAMPLES = 32;

void main() {
    vec4 src = texture(u_source, v_uv);
    if (u_sun_shafts.w < 0.5 || u_sun_shafts.z <= 0.0) {
        frag_color = src;
        return;
    }
    float aspect = u_resolution.x / max(u_resolution.y, 1.0);
    vec2 toSun = u_sun_shafts.xy - v_uv;
    // Shafts reach about 0.7 of the screen height from the sun.
    float reach = length(toSun * vec2(aspect, 1.0));
    vec2 stepUv = toSun / float(SAMPLES);
    float jitter = fract(52.9829189 * fract(dot(gl_FragCoord.xy, vec2(0.06711056, 0.00583715))));
    vec2 uv = v_uv + stepUv * jitter;
    float decay = 1.0;
    float sum = 0.0;
    for (int i = 0; i < SAMPLES; i++) {
        uv += stepUv;
        if (uv.x < 0.0 || uv.x > 1.0 || uv.y < 0.0 || uv.y > 1.0) {
            decay *= 0.94;
            continue;
        }
        float sky = texture(u_scene_depth_raw, uv).r >= 0.99999 ? 1.0 : 0.0;
        // Open sky near the sun is the source (its brightness does not matter:
        // a hazy sun without a disc still streams); what stands in front cuts it.
        float near = exp(-length((uv - u_sun_shafts.xy) * vec2(aspect, 1.0)) * 4.0);
        sum += sky * near * decay;
        decay *= 0.94;
    }
    sum /= float(SAMPLES);
    float fall = 1.0 - smoothstep(0.1, 0.75, reach);
    vec3 add = u_sun_shaft_color.rgb * sum * fall * u_sun_shafts.z * 0.6;
    frag_color = vec4(src.rgb + add, src.a);
}
