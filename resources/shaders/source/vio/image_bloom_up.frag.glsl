#version 410 core
// Upsample step of the image chain's bloom: a 3x3 tent over the coarser level, scaled by
// the scatter weight and added to this level's downsample. The finest level ends up as
// sum(scatter^i * level_i); image_finish divides by sum(scatter^i).
in vec2 v_uv;
uniform sampler2D u_source;  // the coarser level, already upsampled up to there
uniform sampler2D u_base;    // this level's downsample
uniform vec2  u_texel;       // 1 / size of u_source
uniform float u_scatter;
out vec4 frag_color;

void main() {
    vec2 t = u_texel;
    vec3 s = texture(u_source, v_uv).rgb * 4.0
           + (texture(u_source, v_uv + vec2(-t.x, 0.0)).rgb + texture(u_source, v_uv + vec2(t.x, 0.0)).rgb
            + texture(u_source, v_uv + vec2(0.0, -t.y)).rgb + texture(u_source, v_uv + vec2(0.0, t.y)).rgb) * 2.0
           + texture(u_source, v_uv + vec2(-t.x, -t.y)).rgb + texture(u_source, v_uv + vec2(t.x, -t.y)).rgb
           + texture(u_source, v_uv + vec2(-t.x, t.y)).rgb + texture(u_source, v_uv + vec2(t.x, t.y)).rgb;
    frag_color = vec4(texture(u_base, v_uv).rgb + s * (u_scatter / 16.0), 1.0);
}
