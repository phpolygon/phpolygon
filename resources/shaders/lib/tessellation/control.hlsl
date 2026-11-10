// The control stage of TessellationShaderSource as a Direct3D hull shader, given to php-vio as
// the 'tess_control' HLSL override (VIO_FEATURE_HLSL_STAGE_OVERRIDE). Same work as
// control_head.glsl + control_tail.glsl: attributes passed through, each edge split by its
// distance to the camera. Here the levels come from a plain patch constant function over the
// input patch; the hull shader php-vio transpiles from the GLSL replays the control-point main
// three times inside it, and with levels that depend on the control points the Intel D3D12
// driver's shader compiler aborts the process on that (UHD 630, 2026-11-10).
//
// Uniforms: those of the GLSL control stage, in its order (php-vio checks the layout).
// Inputs and outputs: the semantics SPIRV-Cross gives the attributes (TEXCOORD<location>).
// Domain, partitioning and winding mirror the evaluation stage's
// `layout(triangles, fractional_odd_spacing, ccw)` the way php-vio maps them.

cbuffer TessUniforms : register(b0)
{
    float4 u_tess : packoffset(c0);              // max level, full-detail distance, level-1 distance, on
    float3 u_tess_eye : packoffset(c1);          // camera position
    row_major float4x4 u_tess_model : packoffset(c2); // model matrix of a single draw
    int u_tess_instanced : packoffset(c6);       // 1: the model matrix rides in the instance attributes
};

struct TessPoint
{
    float3 position : TEXCOORD0;
    float3 normal : TEXCOORD1;
    float2 uv : TEXCOORD2;
    float4 instanceCol0 : TEXCOORD3;
    float4 instanceCol1 : TEXCOORD4;
    float4 instanceCol2 : TEXCOORD5;
    float4 instanceCol3 : TEXCOORD6;
    float4 position4 : SV_Position;
};

struct TessLevels
{
    float outer[3] : SV_TessFactor;
    float inner : SV_InsideTessFactor;
};

float tessEdgeLevel(float3 a, float3 b)
{
    float d = distance((a + b) * 0.5, u_tess_eye);
    float t = saturate((d - u_tess.y) / max(u_tess.z - u_tess.y, 1e-3));
    return max(1.0, lerp(u_tess.x, 1.0, t));
}

float3 tessWorld(TessPoint p, float4x4 model)
{
    return mul(float4(p.position, 1.0), model).xyz;
}

TessLevels tessLevels(InputPatch<TessPoint, 3> patch)
{
    float4x4 model = u_tess_model;
    if (u_tess_instanced == 1) {
        model = float4x4(float4(patch[0].instanceCol0.xyz, 0.0), float4(patch[0].instanceCol1.xyz, 0.0),
                         float4(patch[0].instanceCol2.xyz, 0.0), float4(patch[0].instanceCol3.xyz, 1.0));
    }
    float3 p0 = tessWorld(patch[0], model);
    float3 p1 = tessWorld(patch[1], model);
    float3 p2 = tessWorld(patch[2], model);
    bool on = u_tess.w > 0.5;
    TessLevels levels;
    levels.outer[0] = on ? tessEdgeLevel(p1, p2) : 1.0;
    levels.outer[1] = on ? tessEdgeLevel(p2, p0) : 1.0;
    levels.outer[2] = on ? tessEdgeLevel(p0, p1) : 1.0;
    levels.inner = max(levels.outer[0], max(levels.outer[1], levels.outer[2]));
    return levels;
}

[domain("tri")]
[partitioning("fractional_odd")]
[outputtopology("triangle_cw")]
[outputcontrolpoints(3)]
[patchconstantfunc("tessLevels")]
TessPoint main(InputPatch<TessPoint, 3> patch, uint id : SV_OutputControlPointID)
{
    return patch[id];
}
